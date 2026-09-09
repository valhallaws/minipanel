<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Jobs\RunSiteLifecycle;
use App\Livewire\Dashboard;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SiteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain = 'example.test', array $attributes = []): Site
    {
        return Site::create(['name' => $domain, 'domain' => $domain, 'path' => '/var/www/'.$domain, 'status' => 'active', 'php_version' => '8.3', ...$attributes]);
    }

    public function test_delete_defaults_to_trash_and_includes_children_before_parent(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunSiteLifecycle::class]);
        $site = $this->site();
        $child = $this->site('child.example.test', ['parent_site_id' => $site->id]);

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->call('openSiteAction', $site->id, 'delete')->assertSet('deleteImmediately', false)
            ->assertSee('child.example.test')->call('confirmSiteAction')->assertHasNoErrors();

        Queue::assertPushed(RunSiteLifecycle::class, fn ($job) => $job->action === 'trash' && $job->siteIds === [$child->id, $site->id]);
        $this->assertFalse($site->fresh()->trashed());
        $this->assertSame('trash', $site->fresh()->lifecycle_action);
    }

    public function test_immediate_deletion_requires_the_explicit_option(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunSiteLifecycle::class]);
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->call('openSiteAction', $site->id, 'delete')
            ->set('deleteImmediately', true)->assertSee('irreversible')->call('confirmSiteAction')->assertHasNoErrors();

        Queue::assertPushed(RunSiteLifecycle::class, fn ($job) => $job->action === 'purge');
        $this->assertModelExists($site);
    }

    public function test_guest_cannot_request_domain_actions(): void
    {
        Queue::fake();
        $site = $this->site();

        Livewire::test(Dashboard::class)->call('openSiteAction', $site->id, 'delete')->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNull($site->fresh()->lifecycle_action);
    }

    public function test_rename_reserves_new_name_without_moving_resources_before_agent_success(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([RunSiteLifecycle::class]);
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->call('openSiteAction', $site->id, 'rename')
            ->set('newDomain', 'new.example.test')->call('confirmSiteAction')->assertHasNoErrors();

        $this->assertSame('example.test', $site->fresh()->domain);
        $this->assertSame('new.example.test', $site->fresh()->pending_domain);
        Queue::assertPushed(RunSiteLifecycle::class, fn ($job) => $job->action === 'rename');
    }

    public function test_rename_rejects_a_name_reserved_in_trash(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        $site = $this->site();
        $reserved = $this->site('reserved.test');
        $reserved->delete();

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->call('openSiteAction', $site->id, 'rename')
            ->set('newDomain', 'reserved.test')->call('confirmSiteAction')->assertHasErrors('newDomain');

        $this->assertNull($site->fresh()->lifecycle_action);
        Queue::assertNothingPushed();
    }

    public function test_rename_success_preserves_server_namespace_and_detaches_unchanged_children(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        $site = $this->site(attributes: ['lifecycle_action' => 'rename', 'server_domain' => 'example.test', 'pending_domain' => 'renamed.test', 'ssl_enabled' => true]);
        $child = $this->site('child.example.test', ['parent_site_id' => $site->id]);
        Process::fake(['*' => Process::result(output: json_encode(['action' => 'rename', 'domain' => 'example.test']))]);

        (new RunSiteLifecycle([$site->id], 'rename'))->handle();

        $this->assertSame('renamed.test', $site->fresh()->domain);
        $this->assertSame('/var/www/example.test', $site->fresh()->path);
        $this->assertSame('example.test', $site->fresh()->resourceDomain());
        $this->assertNull($child->fresh()->parent_site_id);
        $this->assertFalse($site->fresh()->ssl_enabled);
        Process::assertRan(fn ($process) => $process->command[4] === 'example.test' && str_contains($process->input, 'renamed.test'));
    }

    public function test_trash_preserves_database_records_and_uses_agent_deadline(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $this->freezeTime();
        Storage::fake('local');
        $site = $this->site(attributes: ['lifecycle_action' => 'trash']);
        $database = $site->databases()->create(['name' => 'kept_database']);
        $deadline = now()->addDays(5)->timestamp;
        Process::fake(['*' => Process::result(output: json_encode(['action' => 'trash', 'domain' => 'example.test', 'purge_after' => $deadline]))]);

        (new RunSiteLifecycle([$site->id], 'trash'))->handle();

        $this->assertSoftDeleted($site);
        $this->assertSame($deadline, Site::onlyTrashed()->findOrFail($site->id)->purge_after->timestamp);
        $this->assertModelExists($database);
        Process::assertRan(fn ($process) => end($process->command) === 'trash');
    }

    public function test_restore_reinstates_suspended_state_only_after_agent_success(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        $site = $this->site(attributes: ['lifecycle_action' => 'restore', 'lifecycle_previous_status' => 'suspended', 'purge_after' => now()->addDays(2)]);
        $site->delete();
        Process::fake(['*' => Process::result(output: json_encode(['action' => 'restore', 'domain' => 'example.test']))]);

        (new RunSiteLifecycle([$site->id], 'restore'))->handle();

        $this->assertSame('suspended', Site::findOrFail($site->id)->status);
        $this->assertNull(Site::findOrFail($site->id)->purge_after);
        Process::assertRan(fn ($process) => end($process->command) === 'restore');
    }

    public function test_purge_cleans_related_records_only_after_agent_confirms_success(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        $site = $this->site(attributes: ['lifecycle_action' => 'purge']);
        $database = $site->databases()->create(['name' => 'delete_database']);
        $user = $site->databaseUsers()->create(['site_database_id' => $database->id, 'name' => 'delete_user', 'password' => 'testing-only-password']);
        $other = $this->site('other.test');
        Process::fake(['*' => Process::result(output: json_encode(['action' => 'purge', 'domain' => 'example.test']))]);

        (new RunSiteLifecycle([$site->id], 'purge'))->handle();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertModelMissing($database);
        $this->assertModelMissing($user);
        $this->assertModelExists($other);
        Process::assertRan(fn ($process) => end($process->command) === 'purge');
    }

    public function test_failure_retains_domain_and_exposes_recoverable_error(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $site = $this->site(attributes: ['lifecycle_action' => 'purge']);
        Process::fake(['*' => Process::result(errorOutput: 'Shared resources require review', exitCode: 1)]);

        (new RunSiteLifecycle([$site->id], 'purge'))->handle();

        $this->assertModelExists($site);
        $this->assertSame('Shared resources require review', $site->fresh()->lifecycle_error);
        Process::assertRan(fn ($process) => end($process->command) === 'purge');
    }

    public function test_queued_database_import_prevents_deletion(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        $site = $this->site();
        SiteDatabaseOperation::factory()->create(['site_id' => $site->id, 'status' => 'pending']);

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->call('openSiteAction', $site->id, 'delete')
            ->call('confirmSiteAction')->assertHasErrors('siteAction');

        $this->assertNull($site->fresh()->lifecycle_action);
        Queue::assertNothingPushed();
    }

    public function test_expired_trash_is_scheduled_but_future_trash_is_kept(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $this->freezeTime();
        Queue::fake([RunSiteLifecycle::class]);
        $expired = $this->site(attributes: ['purge_after' => now()->subMinute()]);
        $expired->delete();
        $future = $this->site('future.test', ['purge_after' => now()->addDay()]);
        $future->delete();

        $this->artisan('minipanel:purge-site-trash')->assertSuccessful();

        Queue::assertPushed(RunSiteLifecycle::class, fn ($job) => $job->siteIds === [$expired->id]);
        Queue::assertPushed(RunSiteLifecycle::class, 1);
        $this->assertNull(Site::onlyTrashed()->findOrFail($future->id)->lifecycle_action);
    }

    public function test_legacy_destructive_agent_action_is_blocked(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake();
        $site = $this->site();
        $deployment = Deployment::create(['site_id' => $site->id, 'action' => 'delete-site', 'status' => 'queued']);

        (new RunDeployment($deployment->id))->handle();

        $this->assertSame('blocked', $deployment->fresh()->status);
        $this->assertModelExists($site);
        Process::assertNothingRan();
    }
}
