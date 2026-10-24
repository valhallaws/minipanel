<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledBackupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_backups_queue_only_eligible_sites(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunDeployment::class]);
        $eligible = $this->site('eligible.example.test');
        $this->site('disabled.example.test')->update(['backup_enabled' => false]);
        $this->site('suspended.example.test')->update(['status' => 'suspended']);
        $busy = $this->site('busy.example.test');
        Deployment::create(['site_id' => $busy->id, 'action' => 'backup', 'status' => 'running']);

        $this->artisan('minipanel:run-scheduled-backups')->assertSuccessful();

        $backup = Deployment::query()->where('site_id', $eligible->id)->sole();
        $this->assertSame('backup', $backup->action);
        $this->assertSame('queued', $backup->status);
        $this->assertSame(['scheduled' => true], $backup->parameters);
        $this->assertDatabaseCount('deployments', 2);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job): bool => $job->deploymentId === $backup->id);
    }

    public function test_successful_backup_is_verified_before_older_copies_are_pruned(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunDeployment::class]);
        Process::fake(fn () => Process::result('/var/backups/minipanel/example.test-20261024-021500.tar.gz'));
        $site = $this->site('example.test', ['backup_retention' => 1]);
        $oldBackup = Deployment::create(['site_id' => $site->id, 'action' => 'backup', 'status' => 'finished', 'output' => '/var/backups/minipanel/example.test-20261023-021500.tar.gz', 'finished_at' => now()->subDay()]);
        $backup = Deployment::create(['site_id' => $site->id, 'action' => 'backup', 'status' => 'queued']);

        (new RunDeployment($backup->id))->handle();

        $verification = Deployment::query()->where('action', 'verify-backup')->sole();
        $this->assertSame(['/var/backups/minipanel/example.test-20261024-021500.tar.gz'], $verification->parameters['arguments']);
        $this->assertNotNull($site->fresh()->last_backup_at);

        (new RunDeployment($verification->id))->handle();

        $cleanup = Deployment::query()->where('action', 'prune-backups')->sole();
        $this->assertSame(['1'], $cleanup->parameters['arguments']);
        $this->assertNotNull($site->fresh()->backup_last_verified_at);

        (new RunDeployment($cleanup->id))->handle();

        $this->assertSame('pruned', $oldBackup->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(string $domain, array $attributes = []): Site
    {
        return Site::create([...[
            'name' => $domain,
            'domain' => $domain,
            'path' => '/var/www/'.$domain,
            'status' => 'active',
            'runtime' => 'static',
            'php_version' => '8.4',
            'document_root' => 'httpdocs',
        ], ...$attributes]);
    }
}
