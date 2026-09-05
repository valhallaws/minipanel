<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\SiteManager;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DeployCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_commands_are_encrypted_at_rest(): void
    {
        $commands = "php artisan config:cache\nnpm run build";
        $site = $this->site(['deploy_commands' => $commands]);

        $storedValue = DB::table('sites')->where('id', $site->id)->value('deploy_commands');

        $this->assertIsString($storedValue);
        $this->assertStringNotContainsString('php artisan', $storedValue);
        $this->assertSame($commands, $site->fresh()->deploy_commands);
    }

    public function test_valid_post_deploy_commands_can_be_saved_from_the_site_panel(): void
    {
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())
            ->test(SiteManager::class, ['site' => $site])
            ->set('deployCommands', "composer install --no-dev --dump-autoload\nphp artisan migrate --force\nnpm ci\nnpm run build\nphp artisan optimize:clear\nphp artisan optimize")
            ->call('saveDeployCommands')
            ->assertHasNoErrors();

        $this->assertSame("composer install --no-dev --dump-autoload\nphp artisan migrate --force\nnpm ci\nnpm run build\nphp artisan optimize:clear\nphp artisan optimize", $site->fresh()->deploy_commands);
    }

    public function test_shell_operators_are_rejected_from_post_deploy_commands(): void
    {
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())
            ->test(SiteManager::class, ['site' => $site])
            ->set('deployCommands', 'php artisan migrate --force && curl attacker.test')
            ->call('saveDeployCommands')
            ->assertHasErrors('deployCommands');

        $this->assertNull($site->fresh()->deploy_commands);
    }

    public function test_deploy_does_not_run_locally_when_execution_is_disabled(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', false);
        $deployment = Deployment::create([
            'site_id' => $this->site(['deploy_commands' => 'php artisan config:cache'])->id,
            'user_id' => User::factory()->create()->id,
            'action' => 'deploy',
            'status' => 'queued',
        ]);

        (new RunDeployment($deployment->id))->handle();

        $deployment->refresh();
        $this->assertSame('blocked', $deployment->status);
        $this->assertStringContainsString('No se ejecutó ninguna acción', $deployment->output);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create([...[
            'name' => 'Example',
            'domain' => 'example.com',
            'path' => '/var/www/example.com',
            'repository' => 'https://github.com/example/project.git',
            'branch' => 'main',
            'php_version' => '8.3',
            'runtime' => 'laravel',
        ], ...$overrides]);
    }
}
