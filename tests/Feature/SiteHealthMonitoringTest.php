<?php

namespace Tests\Feature;

use App\Console\Commands\CheckSiteHealth;
use App\Jobs\RunDeployment;
use App\Livewire\SiteManager;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteHealthMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_health_checks_queue_only_active_sites_without_a_pending_check(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunDeployment::class]);
        $eligible = $this->site('healthy.example.test');
        $this->site('suspended.example.test')->update(['status' => 'suspended']);
        $busy = $this->site('busy.example.test');
        Deployment::create(['site_id' => $busy->id, 'action' => 'health', 'status' => 'running']);

        $this->artisan(CheckSiteHealth::class)->assertSuccessful();

        $health = Deployment::query()->where('site_id', $eligible->id)->sole();
        $this->assertSame('health', $health->action);
        $this->assertSame(['arguments' => ['https://healthy.example.test'], 'scheduled' => true], $health->parameters);
        $this->assertDatabaseCount('deployments', 2);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job): bool => $job->deploymentId === $health->id);
    }

    public function test_successful_health_check_records_the_http_code_latency_and_site_status(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{"status":"healthy","status_code":200,"response_time_ms":143,"error":""}'));
        $site = $this->site('healthy.example.test');
        $deployment = Deployment::create([
            'site_id' => $site->id,
            'action' => 'health',
            'parameters' => ['arguments' => ['https://healthy.example.test']],
            'status' => 'queued',
        ]);

        (new RunDeployment($deployment->id))->handle();

        $this->assertDatabaseHas('site_health_checks', [
            'site_id' => $site->id,
            'status' => 'healthy',
            'status_code' => 200,
            'response_time_ms' => 143,
        ]);
        $this->assertSame('healthy', $site->fresh()->health_status);
        $this->assertNotNull($site->fresh()->health_checked_at);
        $this->assertSame('finished', $deployment->fresh()->status);
        $this->assertStringContainsString('HTTP 200', $deployment->fresh()->output);
    }

    public function test_failed_http_response_is_recorded_without_failing_the_monitor_job(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{"status":"failed","status_code":503,"response_time_ms":74,"error":"El sitio respondió HTTP 503."}'));
        $site = $this->site('offline.example.test');
        $deployment = Deployment::create(['site_id' => $site->id, 'action' => 'health', 'status' => 'queued']);

        (new RunDeployment($deployment->id))->handle();

        $this->assertDatabaseHas('site_health_checks', [
            'site_id' => $site->id,
            'status' => 'failed',
            'status_code' => 503,
            'response_time_ms' => 74,
        ]);
        $this->assertSame('failed', $site->fresh()->health_status);
        $this->assertSame('finished', $deployment->fresh()->status);
    }

    public function test_site_monitoring_displays_the_recent_history(): void
    {
        $site = $this->site('healthy.example.test');
        $site->healthChecks()->create([
            'status' => 'degraded',
            'status_code' => 200,
            'response_time_ms' => 2100,
            'error' => 'La respuesta tardó más de 2 segundos.',
            'checked_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->assertSee('Monitorización')
            ->assertSee('Disponible, pero lenta')
            ->assertSee('2100 ms');
    }

    private function site(string $domain): Site
    {
        return Site::create([
            'name' => $domain,
            'domain' => $domain,
            'path' => '/var/www/'.$domain,
            'status' => 'active',
            'runtime' => 'static',
            'php_version' => '8.4',
            'document_root' => 'httpdocs',
        ]);
    }
}
