<?php

namespace Tests\Feature;

use App\Jobs\RunRepositorySync;
use App\Livewire\GitManager;
use App\Models\Site;
use App\Models\SiteRepository;
use App\Models\User;
use App\Services\DomainGit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GitManagerTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain = 'example.test'): Site
    {
        return Site::create(['name' => $domain, 'domain' => $domain, 'path' => '/var/www/'.$domain, 'status' => 'active', 'runtime' => 'static', 'php_version' => '8.3', 'document_root' => 'httpdocs']);
    }

    public function test_git_page_requires_login(): void
    {
        $this->get(route('sites.git', $this->site()))->assertRedirect(route('login'));
    }

    public function test_lists_only_repositories_of_the_domain_and_escapes_names(): void
    {
        $site = $this->site();
        SiteRepository::factory()->for($site)->create(['name' => '<script>alert(1)</script>']);
        SiteRepository::factory()->for($this->site('other.test'))->create(['name' => 'Other repository']);
        $this->actingAs(User::factory()->create())->get(route('sites.git', $site))
            ->assertSee('Repositorios Git')->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Other repository');
    }

    public function test_key_is_created_synchronously_and_reused_for_the_draft(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        Process::fake(fn () => Process::result('{"public_key":"ssh-ed25519 test"}'));
        $component = Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $this->site()]);

        $component->call('openCreate')->assertHasNoErrors()->assertSee('ssh-ed25519 test')->call('closeCreate')->call('openCreate');

        Process::assertRanTimes(fn ($process) => in_array('key', $process->command, true), 1);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('site_repositories', 1);
    }

    public function test_creation_queues_initial_clone_without_asking_for_branch_or_changing_hosting(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunRepositorySync::class]);
        Process::fake(fn () => Process::result('{"public_key":"ssh-ed25519 test"}'));
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $site])->call('openCreate')
            ->set('name', 'Application')->set('url', 'git@example.com:team/app.git')->set('directory', 'apps/project')
            ->call('createRepository')->assertHasNoErrors()->assertSet('showCreate', false);

        $repository = $site->repositories()->sole();
        $this->assertSame('queued', $repository->status);
        $this->assertSame('apps/project', $repository->directory);
        $this->assertSame('httpdocs', $site->fresh()->document_root);
        Queue::assertPushed(RunRepositorySync::class, fn ($job) => $job->repositoryId === $repository->id && $job->branch === null);
    }

    public function test_https_and_overlapping_directories_are_rejected(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        Process::fake(fn () => Process::result('{"public_key":"ssh-ed25519 test"}'));
        $site = $this->site();
        SiteRepository::factory()->for($site)->create(['directory' => 'apps/existing']);

        $component = Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $site])->call('openCreate')->set('name', 'New repo');
        $component->set('url', 'https://example.com/team/app.git')->call('createRepository')->assertHasErrors('url');
        $component->set('url', 'git@example.com:team/app.git')->set('directory', 'apps')->call('createRepository')->assertHasErrors('directory');
        $component->set('directory', '../outside')->call('createRepository')->assertHasErrors('directory');

        Queue::assertNothingPushed();
    }

    public function test_site_deploy_script_is_saved_from_git_configuration(): void
    {
        $site = $this->site();
        $commands = "composer install --no-interaction --no-dev\nnpm ci\nphp artisan migrate --force";

        Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $site])
            ->set('siteDeployCommands', $commands)
            ->call('saveSiteDeployCommands')
            ->assertHasNoErrors();

        $this->assertSame($commands, $site->fresh()->deploy_commands);
    }

    public function test_cross_domain_pull_is_not_allowed(): void
    {
        Queue::fake();
        $repository = SiteRepository::factory()->for($this->site('other.test'))->create();
        Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $this->site()])->call('syncRepository', $repository->id)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_worker_records_actual_steps_and_metadata(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $site = $this->site();
        $repository = SiteRepository::factory()->for($site)->create(['directory' => 'httpdocs', 'status' => 'queued', 'operation_token' => 'token']);
        Process::fake(fn () => Process::describe()->output([
            json_encode(['step' => 'Clonando', 'status' => 'running']),
            json_encode(['step' => 'Clonando', 'status' => 'done']),
            json_encode(['result' => ['branch' => 'trunk', 'branches' => ['trunk', 'develop'], 'commits' => [], 'project_type' => 'Laravel']]),
        ]));

        (new RunRepositorySync($repository->id, 'token'))->handle(app(DomainGit::class));

        $repository->refresh();
        $this->assertSame('ready', $repository->status);
        $this->assertSame('trunk', $repository->branch);
        $this->assertSame('done', collect($repository->steps)->firstWhere('label', 'Clonando')['status']);
        $this->assertSame('trunk', $site->fresh()->branch);
        Process::assertRanTimes(fn ($process) => in_array('sync', $process->command, true), 1);
    }

    public function test_initial_clone_only_clones_and_detects_the_project(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $site = $this->site();
        $site->update(['deploy_commands' => 'php artisan migrate --force']);
        $repository = SiteRepository::factory()->for($site)->create(['directory' => 'httpdocs', 'status' => 'queued', 'operation_token' => 'token', 'prepare_project' => true]);
        $input = null;
        Process::fake(function ($process) use (&$input) {
            $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            return Process::describe()->output([
                json_encode(['step' => 'Validando clave y conexión', 'status' => 'done']),
                json_encode(['result' => ['branch' => 'main', 'branches' => ['main'], 'commits' => [], 'project_type' => 'Laravel']]),
            ]);
        });

        (new RunRepositorySync($repository->id, 'token'))->handle(app(DomainGit::class));

        $this->assertTrue($input['initial']);
        $this->assertFalse($input['prepare']);
        $this->assertSame('', $input['commands']);
        $labels = collect($repository->fresh()->steps)->pluck('label');
        $this->assertFalse($labels->contains('Preparando el proyecto'));
        $this->assertFalse($labels->contains('Ejecutando script de deploy'));
    }

    public function test_worker_positions_conditional_maintenance_before_the_file_update_steps(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $repository = SiteRepository::factory()->for($this->site())->create([
            'status' => 'queued',
            'operation_token' => 'token',
            'project_type' => 'Laravel',
            'synced_at' => now(),
        ]);
        Process::fake(fn () => Process::describe()->output([
            json_encode(['step' => 'Validando clave y conexión', 'status' => 'done']),
            json_encode(['step' => 'Activando mantenimiento Laravel', 'status' => 'done']),
            json_encode(['result' => ['branch' => 'main', 'branches' => ['main'], 'commits' => [], 'project_type' => 'Laravel']]),
        ]));

        (new RunRepositorySync($repository->id, 'token'))->handle(app(DomainGit::class));

        $labels = collect($repository->fresh()->steps)->pluck('label')->all();
        $this->assertLessThan(array_search('Obteniendo archivos', $labels, true), array_search('Activando mantenimiento Laravel', $labels, true));
        $this->assertLessThan(array_search('Activando mantenimiento Laravel', $labels, true), array_search('Validando clave y conexión', $labels, true));
    }

    public function test_worker_lists_the_post_deploy_script_before_it_runs(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $site = $this->site();
        $site->update(['deploy_commands' => "php artisan migrate --force\nnpm run build"]);
        $repository = SiteRepository::factory()->for($site)->create(['directory' => 'httpdocs', 'status' => 'queued', 'operation_token' => 'token', 'synced_at' => now()]);
        Process::fake(fn () => Process::describe()->output([
            json_encode(['step' => 'Validando clave y conexión', 'status' => 'running']),
            json_encode(['step' => 'Validando clave y conexión', 'status' => 'done']),
            json_encode(['result' => ['branch' => 'trunk', 'branches' => ['trunk'], 'commits' => [], 'project_type' => 'Laravel']]),
        ]));

        (new RunRepositorySync($repository->id, 'token'))->handle(app(DomainGit::class));

        $steps = $repository->fresh()->steps;
        $this->assertSame('done', collect($steps)->firstWhere('label', 'Validando clave y conexión')['status']);
        $this->assertSame('pending', collect($steps)->firstWhere('label', 'Ejecutando script de deploy')['status']);
    }

    public function test_completed_repository_progress_closes_automatically(): void
    {
        $repository = SiteRepository::factory()->for($this->site())->create(['status' => 'ready']);

        Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $repository->site])
            ->call('showProgress', $repository->id)
            ->assertSet('progressId', null)
            ->assertDontSee('Actualización completada. La raíz web no se modificó.');
    }

    public function test_failed_clone_preserves_configuration_for_retry(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $repository = SiteRepository::factory()->for($this->site())->create(['status' => 'queued', 'operation_token' => 'token']);
        Process::fake(fn () => Process::result(errorOutput: 'Permission denied (publickey)', exitCode: 1));

        (new RunRepositorySync($repository->id, 'token'))->handle(app(DomainGit::class));

        $this->assertSame('failed', $repository->fresh()->status);
        $this->assertSame('Permission denied (publickey)', $repository->fresh()->error);
        $this->assertSame($repository->url, $repository->fresh()->url);
        Process::assertRanTimes(fn ($process) => in_array('sync', $process->command, true), 1);
    }

    public function test_webhook_requires_a_nonempty_secret_and_matching_branch(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunRepositorySync::class]);
        $repository = SiteRepository::factory()->for($this->site())->create(['branch' => 'trunk', 'branches' => ['trunk'], 'automatic' => true]);
        $url = route('webhooks.repository', $repository->key_token);
        $this->postJson($url, ['ref' => 'refs/heads/trunk'])->assertUnauthorized();
        $repository->update(['webhook_secret' => 'example-secret']);
        $headers = ['X-Gitlab-Token' => 'example-secret', 'X-Gitlab-Event' => 'Push Hook'];
        $this->postJson($url, ['ref' => 'refs/heads/other'], $headers)->assertJson(['queued' => false]);
        $this->postJson($url, ['ref' => 'refs/heads/trunk'], $headers)->assertJson(['queued' => true]);

        Queue::assertPushed(RunRepositorySync::class, 1);
        $this->assertNotNull($repository->fresh()->last_push_at);
    }

    public function test_github_form_encoded_webhook_uses_the_nested_payload(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunRepositorySync::class]);
        $repository = SiteRepository::factory()->for($this->site())->create(['branch' => 'test84', 'branches' => ['test84'], 'automatic' => true, 'webhook_secret' => 'example-secret']);
        $body = http_build_query(['payload' => json_encode(['ref' => 'refs/heads/test84', 'deleted' => false], JSON_THROW_ON_ERROR)]);
        $headers = [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'example-secret'),
        ];

        $this->call('POST', route('webhooks.repository', $repository->key_token), [], [], [], $headers, $body)
            ->assertJson(['queued' => true]);

        $this->assertNotNull($repository->fresh()->last_push_at);
        Queue::assertPushed(RunRepositorySync::class, 1);
    }

    public function test_suspended_domain_never_invokes_agent(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake();
        $site = $this->site();
        $site->update(['status' => 'suspended']);
        Livewire::actingAs(User::factory()->create())->test(GitManager::class, ['site' => $site])->call('openCreate')->assertHasErrors('git');

        Process::assertNothingRan();
    }
}
