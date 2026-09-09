<?php

namespace Tests\Feature;

use App\Jobs\RunLaravelCommand;
use App\Livewire\LaravelManager;
use App\Livewire\SiteManager;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class LaravelManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_opens_inside_site_without_a_navigation_link(): void
    {
        $repository = $this->repository();
        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $repository->site, 'embedded' => true])
            ->assertDontSee('href="'.route('sites.laravel', $repository->site).'"', false)
            ->call('openLaravel')->assertSet('laravelOpened', true)->assertSeeLivewire(LaravelManager::class);
    }

    public function test_embedded_laravel_has_no_second_page_header_or_global_activity_feed(): void
    {
        $repository = $this->repository();
        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site, 'embedded' => true])
            ->assertSee('Información de la aplicación')->assertDontSee('Laravel · '.$repository->site->domain)->assertDontSee('Resultados y actividad');
    }

    private function repository(string $domain = 'laravel.test'): SiteRepository
    {
        $site = Site::create(['name' => $domain, 'domain' => $domain, 'path' => '/var/www/'.$domain, 'status' => 'active', 'runtime' => 'static', 'php_version' => '8.3']);

        return SiteRepository::factory()->for($site)->create(['directory' => 'apps/erp', 'project_type' => 'Laravel', 'status' => 'ready']);
    }

    public function test_laravel_tools_require_authentication(): void
    {
        $this->get(route('sites.laravel', $this->repository()->site))->assertRedirect(route('login'));
    }

    public function test_detected_repository_exposes_laravel_tools_without_changing_domain_root(): void
    {
        $repository = $this->repository();
        $this->actingAs(User::factory()->create())->get(route('sites.laravel', $repository->site))->assertOk()->assertSee('apps/erp')->assertSee('Artisan')->assertSee('Composer')->assertSee('Node.js')->assertSee('Schedule')->assertSee('Queues');
        $this->assertSame('/var/www/laravel.test', $repository->site->fresh()->path);
    }

    public function test_cannot_select_a_repository_from_another_domain(): void
    {
        $repository = $this->repository();
        $other = $this->repository('other.test');
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])->set('repositoryId', $other->id);
    }

    public function test_artisan_suggestions_include_native_and_custom_commands_without_queueing_them(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        $repository = $this->repository();
        $commands = [['name' => 'about', 'description' => 'Application info'], ['name' => 'orders:sync', 'description' => 'Sync <orders>']];
        Process::fake(fn () => Process::result(json_encode(['maintenance' => false, 'commands' => $commands])));
        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])
            ->call('inspectLaravel')->assertSet('artisanSuggestions', $commands)
            ->assertSee('value="about"', false)->assertSee('value="orders:sync"', false)
            ->assertSee('Sync &lt;orders&gt;', false);
        Process::assertRan(fn ($process) => in_array('laravel-state', $process->command, true) && json_decode($process->input, true)['directory'] === 'apps/erp');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_env_is_loaded_and_saved_synchronously_without_logging_secrets(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        $repository = $this->repository();
        Process::fake(fn ($process) => Process::result(in_array('laravel-env-read', $process->command, true) ? json_encode(['contents' => 'APP_KEY=unchanged', 'hash' => hash('sha256', 'APP_KEY=unchanged')]) : '{"saved":true}'));
        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])->call('openEnvironment')->assertSet('environment', 'APP_KEY=unchanged')->set('environment', "APP_KEY=unchanged\nDB_PASSWORD=private")->call('saveEnvironment')->assertHasNoErrors()->assertSet('showEnvironment', false)->assertSet('environment', '');
        Process::assertRan(fn ($process) => in_array('laravel-env-write', $process->command, true) && json_decode($process->input, true)['directory'] === 'apps/erp');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_maintenance_secret_uses_stdin_not_arguments_or_history(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{"maintenance":true}'));
        $repository = $this->repository();
        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])->set('maintenanceSecret', 'private-secret')->call('setMaintenance', true)->assertHasNoErrors()->assertSet('maintenance', true)->assertSet('maintenanceSecret', '');
        Process::assertRan(fn ($process) => ! str_contains(implode(' ', $process->command), 'private-secret') && json_decode($process->input, true)['secret'] === 'private-secret');
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_console_queues_scoped_command_and_rejects_shell_operators(): void
    {
        Queue::fake([RunLaravelCommand::class]);
        $repository = $this->repository();
        $component = Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site]);
        $component->set('command', 'list; whoami')->call('runCommand')->assertHasErrors(['command']);
        Queue::assertNothingPushed();
        $component->set('command', 'schedule:list')->call('runCommand')->assertHasNoErrors();
        $operation = Deployment::sole();
        $this->assertSame($repository->id, $operation->parameters['repository_id']);
        Queue::assertPushed(RunLaravelCommand::class, fn ($job) => $job->deploymentId === $operation->id);
    }

    public function test_puppeteer_install_is_queued_for_the_selected_laravel_project(): void
    {
        Queue::fake([RunLaravelCommand::class]);
        $repository = $this->repository();

        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])
            ->call('installPuppeteer')
            ->assertHasNoErrors();

        $operation = Deployment::sole();
        $this->assertSame($repository->id, $operation->parameters['repository_id']);
        $this->assertSame('puppeteer', $operation->parameters['task']);
        Queue::assertPushed(RunLaravelCommand::class, fn ($job) => $job->deploymentId === $operation->id);
    }

    public function test_worker_resolves_project_directory_from_its_own_domain(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result("{\"output\":\"Available commands\"}\n"));
        $repository = $this->repository();
        $operation = Deployment::create(['site_id' => $repository->site_id, 'action' => 'laravel-command', 'parameters' => ['repository_id' => $repository->id, 'tool' => 'artisan', 'arguments' => ['list']], 'status' => 'queued']);
        (new RunLaravelCommand($operation->id))->handle();
        $this->assertSame('finished', $operation->fresh()->status);
        $this->assertSame('Available commands', $operation->fresh()->output);
        Process::assertRan(fn ($process) => json_decode($process->input, true)['directory'] === 'apps/erp');
    }

    public function test_puppeteer_worker_uses_the_scoped_agent_operation(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result("{\"step\":\"Descargando Google Chrome\",\"status\":\"done\",\"detail\":\"PUPPETEER_EXECUTABLE_PATH=/var/www/laravel.test/.cache/puppeteer/chrome\"}\n"));
        $repository = $this->repository();
        $operation = Deployment::create(['site_id' => $repository->site_id, 'action' => 'laravel-command', 'parameters' => ['repository_id' => $repository->id, 'task' => 'puppeteer'], 'status' => 'queued']);

        (new RunLaravelCommand($operation->id))->handle();

        $this->assertSame('finished', $operation->fresh()->status);
        $this->assertStringContainsString('PUPPETEER_EXECUTABLE_PATH', $operation->fresh()->output);
        Process::assertRan(fn ($process) => in_array('laravel-puppeteer', $process->command, true) && json_decode($process->input, true) === ['directory' => 'apps/erp']);
    }

    public function test_schedule_and_queue_services_receive_project_scope(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Configured'));
        $repository = $this->repository();
        $operation = Deployment::create(['site_id' => $repository->site_id, 'action' => 'laravel-command', 'parameters' => ['repository_id' => $repository->id, 'service' => 'schedule', 'enabled' => true], 'status' => 'queued']);
        (new RunLaravelCommand($operation->id))->handle();
        $this->assertSame('finished', $operation->fresh()->status);
        Process::assertRan(fn ($process) => in_array('laravel-service', $process->command, true) && in_array('apps/erp', $process->command, true) && in_array('schedule', $process->command, true));
    }

    public function test_schedule_list_renders_tasks_with_a_human_readable_cadence(): void
    {
        $repository = $this->repository();
        Deployment::create([
            'site_id' => $repository->site_id,
            'action' => 'laravel-command',
            'parameters' => ['repository_id' => $repository->id, 'tool' => 'artisan', 'arguments' => ['schedule:list']],
            'status' => 'finished',
            'output' => '  */5 * * * *  php artisan backup:run --only-db ........................ Next Due: in 4 minutes',
        ]);

        Livewire::actingAs(User::factory()->create())->test(LaravelManager::class, ['site' => $repository->site])
            ->assertSee('php artisan backup:run --only-db')
            ->assertSee('Cada 5 minutos')
            ->assertSee('Próxima ejecución')
            ->assertSee('in 4 minutes');
    }
}
