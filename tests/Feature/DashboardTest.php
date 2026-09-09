<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Dashboard;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_tree_uses_one_persisted_expanded_domain_and_shows_server_alias(): void
    {
        $site = Site::create([
            'name' => 'Example',
            'domain' => 'example.com',
            'server_domain' => 'example.test',
            'path' => '/var/www/example.test',
            'status' => 'active',
            'php_version' => '8.3',
        ]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee('minipanel.expanded-domain.', false)
            ->assertDontSee('minipanel.domain.', false)
            ->assertSee('Example')
            ->assertSee('Dominio: example.com')
            ->assertSee('Alias del servidor: example.test')
            ->assertSee('href="https://example.com"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('Papelera (0)');
        $this->assertModelExists($site);
    }

    public function test_provisioning_site_can_be_queued_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $site = Site::create([
            'name' => 'Temporary site',
            'domain' => '159-54-145-184.sslip.io',
            'path' => '/var/www/159-54-145-184.sslip.io',
            'branch' => 'main',
            'php_version' => '8.3',
            'runtime' => 'static',
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('provision', $site->id)
            ->assertHasNoErrors();

        $deployment = Deployment::query()->sole();

        $this->assertSame('provision', $deployment->action);
        $this->assertSame('queued', $deployment->status);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_dashboard_polls_while_a_deployment_is_pending(): void
    {
        $site = Site::create([
            'name' => 'Temporary site',
            'domain' => '159-54-145-184.sslip.io',
            'path' => '/var/www/159-54-145-184.sslip.io',
            'branch' => 'main',
            'php_version' => '8.3',
            'runtime' => 'static',
        ]);
        Deployment::create([
            'site_id' => $site->id,
            'user_id' => User::factory()->create()->id,
            'action' => 'deploy',
            'status' => 'queued',
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee('wire:poll.1500ms');
    }

    public function test_creating_a_domain_only_prepares_hosting(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->set('name', 'Laravel application')
            ->set('domain', 'app.example.com')
            ->call('createSite')
            ->assertHasNoErrors();

        $deployment = Deployment::query()->sole();

        $this->assertSame('provision', $deployment->action);
        $site = Site::query()->sole();
        $this->assertSame('/var/www/app.example.com', $site->path);
        $this->assertNull($site->repository);
        $this->assertFalse($site->queue_enabled);
        $this->assertFalse($site->scheduler_enabled);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_creating_a_static_site_without_a_repository_queues_provisioning(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->set('name', 'Static site')
            ->set('domain', 'static.example.com')
            ->call('createSite')
            ->assertHasNoErrors();

        $deployment = Deployment::query()->sole();

        $this->assertSame('provision', $deployment->action);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_subdomain_is_grouped_under_its_parent(): void
    {
        Queue::fake([RunDeployment::class]);
        $parent = Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'status' => 'active']);

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->set('domain', 'shop.example.com')
            ->set('parentSiteId', $parent->id)
            ->call('createSite')
            ->assertHasNoErrors()
            ->assertSee('shop.example.com');

        $this->assertSame('shop.example.com', $parent->children()->sole()->domain);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_unrelated_domain_cannot_be_assigned_to_a_parent(): void
    {
        Queue::fake();
        $parent = Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'status' => 'active']);

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->set('domain', 'notexample.com')
            ->set('parentSiteId', $parent->id)
            ->call('createSite')
            ->assertHasErrors('domain');

        $this->assertDatabaseCount('sites', 1);
        Queue::assertNothingPushed();
    }

    public function test_suspended_parent_cannot_receive_subdomains(): void
    {
        Queue::fake();
        $parent = Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'status' => 'suspended']);

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->set('domain', 'shop.example.com')
            ->set('parentSiteId', $parent->id)
            ->call('createSite')
            ->assertHasErrors('parentSiteId');

        $this->assertDatabaseCount('sites', 1);
        Queue::assertNothingPushed();
    }
}
