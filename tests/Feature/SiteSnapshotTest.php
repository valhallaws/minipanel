<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteSnapshot;
use App\Jobs\RunDeployment;
use App\Livewire\Dashboard;
use App\Livewire\SiteManager;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SiteSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_is_stored_privately_and_served_only_after_login(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        $image = "\xff\xd8\xfftest";
        Process::fake(fn () => Process::result(base64_encode($image)));
        $site = $this->site();
        (new CaptureSiteSnapshot($site->id))->handle();

        $this->assertSame($image, Storage::disk('local')->get('site-snapshots/'.$site->id.'.jpg'));
        $this->get(route('sites.snapshot', $site))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('sites.snapshot', $site))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        Process::assertRan(fn ($process) => $process->command[2] === 'snapshot' && $process->timeout === 55);
    }

    public function test_failed_capture_preserves_previous_image(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        Process::fake(fn () => Process::result('invalid', exitCode: 1));
        $site = $this->site();
        Storage::disk('local')->put('site-snapshots/'.$site->id.'.jpg', 'old');
        (new CaptureSiteSnapshot($site->id))->handle();
        $this->assertSame('old', Storage::disk('local')->get('site-snapshots/'.$site->id.'.jpg'));
        $this->assertSame('failed', Cache::get('site-snapshot-'.$site->id)['status']);
    }

    public function test_snapshot_requests_are_deduplicated(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake([CaptureSiteSnapshot::class]);
        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $this->site()])
            ->call('refreshSnapshot')->call('refreshSnapshot', true)->assertHasNoErrors();
        Queue::assertPushed(CaptureSiteSnapshot::class, 1);
    }

    public function test_header_status_changes_are_queued_and_do_not_change_status_prematurely(): void
    {
        Queue::fake([RunDeployment::class]);
        $site = $this->site();
        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);
        $component->call('changeSiteStatus', $site->id, 'disabled')->assertHasErrors('siteAction');
        $component->call('changeSiteStatus', $site->id, 'suspended')->assertHasNoErrors();
        $this->assertSame('active', $site->fresh()->status);
        $this->assertSame('suspend-site', Deployment::query()->sole()->action);
        $component->call('changeSiteStatus', $site->id, 'suspended')->assertHasErrors('siteAction');
        Queue::assertPushed(RunDeployment::class, 1);
    }

    private function site(): Site
    {
        return Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'status' => 'active', 'php_version' => '8.3', 'runtime' => 'static']);
    }
}
