<?php

namespace Tests\Feature;

use App\Livewire\ServerHealth;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
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

        Deployment::create(['site_id' => $active->id, 'user_id' => $user->id, 'action' => 'deploy', 'status' => 'running', 'output' => 'Ejecutando agente…']);
        Deployment::create(['site_id' => $active->id, 'user_id' => $user->id, 'action' => 'provision', 'status' => 'running', 'output' => 'Esperando agente…', 'started_at' => now()->subMinutes(31)]);
        Deployment::create(['site_id' => $failed->id, 'user_id' => $user->id, 'action' => 'backup', 'status' => 'failed', 'output' => 'No se pudo crear el respaldo.', 'finished_at' => now()]);
        $lifecycle->update(['lifecycle_action' => 'purge', 'lifecycle_error' => 'El agente no pudo completar la operación.']);
        $stale->update(['last_backup_at' => now()->subDays(8)]);
        $fresh->update(['last_backup_at' => now()->subDay()]);

        Livewire::actingAs($user)->test(ServerHealth::class)
            ->assertSee('Operación en curso')
            ->assertSee('active.example.test')
            ->assertSee('Operación detenida')
            ->assertSee('Operaciones detenidas')
            ->assertSee('Requiere atención')
            ->assertSee('failed.example.test')
            ->assertSee('Error de ciclo de vida')
            ->assertSee('lifecycle.example.test')
            ->assertSee('Respaldo pendiente')
            ->assertSee('stale.example.test')
            ->assertDontSee('fresh.example.test');
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
