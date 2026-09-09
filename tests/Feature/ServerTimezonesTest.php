<?php

namespace Tests\Feature;

use App\Livewire\ServerSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class ServerTimezonesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_exposes_timezone_tool_without_running_it(): void
    {
        Process::fake();
        $this->actingAs(User::factory()->create())->get(route('server.setup'))->assertOk()->assertSee('CENTRO DE CONTROL DEL VPS')->assertSee('Aplicaciones y bases');
        Process::assertNothingRan();
    }

    public function test_installer_instructions_use_ip_tls_and_a_dedicated_port(): void
    {
        Process::fake();

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('selectServerSection', 'applications')
            ->assertSee('--panel-ip')
            ->assertSee('--panel-port 8443')
            ->assertSee('--certificate-email');
    }

    public function test_import_requires_confirmation(): void
    {
        Process::fake();
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)->call('importTimezones')->assertStatus(422);
        Process::assertNothingRan();
    }

    public function test_disabled_execution_prevents_import(): void
    {
        config()->set('minipanel.execution_enabled', false);
        Process::fake();
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)->set('confirmTimezones', true)->call('importTimezones')->assertHasErrors('timezones');
        Process::assertNothingRan();
    }

    public function test_confirmed_import_runs_only_fixed_agent_action_and_reports_result(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Catálogo cargado: 600 zonas horarias.'));
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)->call('selectServerSection', 'applications')->set('confirmTimezones', true)->call('importTimezones')->assertHasNoErrors()->assertSet('confirmTimezones', false)->assertSee('Catálogo cargado: 600 zonas horarias.');
        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'import-mariadb-timezones']);
    }

    public function test_import_failure_is_visible(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'MariaDB unavailable', exitCode: 1));
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)->call('selectServerSection', 'applications')->set('confirmTimezones', true)->call('importTimezones')->assertHasErrors('timezones')->assertSee('MariaDB unavailable');
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_server_service_restart_uses_only_an_allowlisted_agent_action(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('nginx: active'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('prepareServerAction', 'restart-service', 'nginx')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-restart-service', 'nginx']);
    }

    public function test_panel_php_service_matches_the_php_version_running_the_panel(): void
    {
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('selectServerSection', 'services')
            ->assertSee('PHP-FPM '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
    }

    public function test_service_logs_use_only_an_allowlisted_read_only_agent_action(): void
    {
        Process::fake(fn () => Process::result('2026-09-08T10:00:00 nginx started'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('loadServiceLogs', 'nginx')
            ->assertHasNoErrors()
            ->assertSee('nginx started');

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-service-log', 'nginx']);
    }

    public function test_hostname_change_requires_the_new_fully_qualified_hostname_as_confirmation(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Hostname actualizado: vps.example.com'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->set('serverHostname', 'vps.example.com')
            ->call('prepareServerAction', 'hostname')
            ->set('hostnameConfirmation', 'vps.example.com')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-hostname', 'vps.example.com']);
    }

    public function test_system_update_requires_confirmation_and_starts_only_the_fixed_agent_action(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Actualización iniciada en segundo plano.'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('prepareServerAction', 'system-update')
            ->set('updateConfirmation', 'ACTUALIZAR')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-system-update-start']);
    }

    public function test_firewall_rule_requires_confirmation_and_uses_the_fixed_agent_action(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Regla permitida: 3306/tcp desde 203.0.113.10'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->set('firewallPort', '3306')
            ->set('firewallProtocol', 'tcp')
            ->set('firewallSource', '203.0.113.10')
            ->call('prepareFirewallAllow')
            ->set('firewallConfirmation', 'APLICAR')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-firewall-allow', '3306', 'tcp', '203.0.113.10']);
    }

    public function test_fail2ban_unban_uses_an_active_jail_and_requires_confirmation(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('IP desbloqueada: 203.0.113.10 (sshd)'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->set('securityStatus', ['Bloqueados:sshd' => '203.0.113.10'])
            ->set('fail2banJail', 'sshd')
            ->set('fail2banIp', '203.0.113.10')
            ->call('prepareFail2banUnban')
            ->set('fail2banConfirmation', 'DESBLOQUEAR')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-fail2ban-unban', 'sshd', '203.0.113.10']);
    }

    public function test_ssh_key_removal_uses_only_a_listed_key_number_and_requires_confirmation(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Llave pública eliminada'));

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->set('securityStatus', ['Llave SSH:2' => '256 SHA256:example user@laptop (ED25519)'])
            ->set('sshKeyNumber', '2')
            ->call('prepareSshKeyDelete')
            ->set('sshConfirmation', 'ELIMINAR')
            ->call('runServerAction')
            ->assertHasNoErrors();

        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-ssh-delete-key', '2']);
    }
}
