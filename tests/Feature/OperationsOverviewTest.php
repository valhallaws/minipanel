<?php

namespace Tests\Feature;

use App\Livewire\ServerHealth;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use App\Models\User;
use App\Services\ServerReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_health_surfaces_active_failures_lifecycle_errors_and_stale_backups(): void
    {
        Process::fake();
        $user = User::factory()->create();
        $active = $this->site('active.example.test');
        $failed = $this->site('failed.example.test');
        $lifecycle = $this->site('lifecycle.example.test');
        $stale = $this->site('stale.example.test');
        $fresh = $this->site('fresh.example.test');
        $historical = $this->site('historical.example.test');

        Deployment::create(['site_id' => $active->id, 'user_id' => $user->id, 'action' => 'deploy', 'status' => 'running', 'output' => 'Ejecutando agente…']);
        Deployment::create(['site_id' => $active->id, 'user_id' => $user->id, 'action' => 'provision', 'status' => 'running', 'output' => 'Esperando agente…', 'started_at' => now()->subMinutes(31)]);
        Deployment::create(['site_id' => $failed->id, 'user_id' => $user->id, 'action' => 'backup', 'status' => 'failed', 'output' => 'No se pudo crear el respaldo.', 'finished_at' => now()]);
        Deployment::create(['site_id' => $historical->id, 'user_id' => $user->id, 'action' => 'laravel-command', 'status' => 'failed', 'output' => 'Fallo histórico.', 'finished_at' => now()->subDays(2)]);
        $lifecycle->update(['lifecycle_action' => 'purge', 'lifecycle_error' => 'El agente no pudo completar la operación.']);
        $stale->update(['last_backup_at' => now()->subDays(8)]);
        $fresh->update(['last_backup_at' => now()->subDay()]);
        $historical->update(['last_backup_at' => now()]);

        Livewire::actingAs($user)->test(ServerHealth::class)
            ->assertSee('Operación en curso')
            ->assertSee('active.example.test')
            ->assertSee('Operación detenida')
            ->assertSee('Detenidas')
            ->assertSee('Requiere atención')
            ->assertSee('failed.example.test')
            ->assertDontSee('historical.example.test')
            ->assertSee('Error de ciclo de vida')
            ->assertSee('lifecycle.example.test')
            ->assertSee('Respaldo pendiente')
            ->assertSee('stale.example.test')
            ->assertDontSee('fresh.example.test');
    }

    public function test_ready_dependencies_and_enabled_execution_are_healthy_not_warnings(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('available'));

        $checks = app(ServerReadiness::class)->inspect();

        $this->assertSame([], collect($checks)
            ->reject(fn (array $check): bool => $check['name'] === 'Agente MiniPanel')
            ->where('status', '!=', 'ok')
            ->all());
        $this->assertSame('Habilitada: el agente puede hacer cambios', collect($checks)->firstWhere('name', 'Ejecución administrativa')['detail']);
    }

    public function test_server_health_alerts_after_two_failed_checks_and_for_expiring_certificates(): void
    {
        Process::fake();
        $user = User::factory()->create();
        $failed = $this->site('offline.example.test');
        $degraded = $this->site('slow.example.test');
        $certificate = $this->site('certificate.example.test');
        $singleFailure = $this->site('one-failure.example.test');
        foreach ([$failed, $degraded, $certificate, $singleFailure] as $site) {
            $site->update(['last_backup_at' => now()]);
        }

        foreach ([now()->subMinutes(10), now()] as $checkedAt) {
            SiteHealthCheck::create(['site_id' => $failed->id, 'status' => 'failed', 'error' => 'No se pudo conectar.', 'checked_at' => $checkedAt]);
        }
        $failed->update(['health_status' => 'failed', 'health_checked_at' => now()]);
        $degraded->update(['health_status' => 'degraded', 'health_checked_at' => now()]);
        $certificate->update(['ssl_enabled' => true, 'ssl_expires_at' => now()->addDays(10)]);
        SiteHealthCheck::create(['site_id' => $singleFailure->id, 'status' => 'failed', 'error' => 'No se pudo conectar.', 'checked_at' => now()]);
        $singleFailure->update(['health_status' => 'failed', 'health_checked_at' => now()]);

        Livewire::actingAs($user)->test(ServerHealth::class)
            ->assertSee('Disponibilidad')
            ->assertSee('offline.example.test')
            ->assertSee('slow.example.test')
            ->assertDontSee('one-failure.example.test')
            ->assertSee('Certificados')
            ->assertSee('certificate.example.test');
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
