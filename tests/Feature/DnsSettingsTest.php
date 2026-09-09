<?php

namespace Tests\Feature;

use App\Livewire\ServerSetup;
use App\Models\DnsSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DnsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_can_be_left_pending_without_server_configuration(): void
    {
        Process::fake();
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('saveDnsSettings')->assertHasNoErrors()->assertSee('Pendiente de completar');
        $this->assertNull(DnsSetting::sole()->infrastructure_domain);
        $this->assertDatabaseCount('server_settings', 0);
        Process::assertNothingRan();
    }

    public function test_complete_dns_draft_persists_without_claiming_delegation(): void
    {
        $user = User::factory()->create();
        $servers = [['hostname' => 'ns1.example.com', 'ip' => '203.0.113.1'], ['hostname' => 'ns2.example.com', 'ip' => '2001:db8::2']];
        Livewire::actingAs($user)->test(ServerSetup::class)->set('dnsDomain', 'EXAMPLE.COM')
            ->set('dnsNameservers', $servers)->call('saveDnsSettings')->assertHasNoErrors()
            ->assertSee('Datos completos')->assertSee('Delegación: no verificada');
        Livewire::actingAs($user)->test(ServerSetup::class)->assertSet('dnsDomain', 'example.com')->assertSet('dnsNameservers', $servers);
    }

    public function test_invalid_names_and_addresses_are_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->set('dnsDomain', 'https://example.com')->set('dnsNameservers.0.hostname', '*.example.com')
            ->set('dnsNameservers.0.ip', 'not-an-ip')->call('saveDnsSettings')
            ->assertHasErrors(['dnsDomain', 'dnsNameservers.0.hostname', 'dnsNameservers.0.ip']);
        $this->assertDatabaseCount('dns_settings', 0);
    }

    public function test_dns_settings_require_authentication(): void
    {
        $this->get(route('server.setup'))->assertRedirect(route('login'));
    }
}
