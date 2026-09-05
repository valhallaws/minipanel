<?php

namespace Tests\Feature;

use App\Jobs\RunSiteFileOperation;
use App\Models\Site;
use App\Models\SiteFileOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_manager_requires_an_authenticated_user(): void
    {
        $site = $this->site();

        $this->get(route('sites.files', $site))
            ->assertRedirect(route('login'));
    }

    public function test_opening_file_manager_queues_a_root_listing_without_reading_local_files(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', false);
        $site = $this->site();

        $this->actingAs(User::factory()->create())
            ->get(route('sites.files', $site))
            ->assertSee('FILE MANAGER');

        $operation = SiteFileOperation::query()->sole();

        $this->assertSame('file-list', $operation->action);
        $this->assertSame('.', $operation->path);
        Queue::assertPushed(RunSiteFileOperation::class, fn (RunSiteFileOperation $job): bool => $job->operationId === $operation->id);
    }

    public function test_file_operation_is_blocked_when_execution_is_disabled(): void
    {
        config()->set('minipanel.execution_enabled', false);
        $operation = SiteFileOperation::create([
            'site_id' => $this->site()->id,
            'user_id' => User::factory()->create()->id,
            'action' => 'file-list',
            'path' => '.',
            'status' => 'queued',
        ]);

        (new RunSiteFileOperation($operation->id))->handle();

        $operation->refresh();
        $this->assertSame('blocked', $operation->status);
        $this->assertStringContainsString('No se accedió a archivos', $operation->output);
    }

    public function test_download_does_not_cross_site_boundaries(): void
    {
        $user = User::factory()->create();
        $site = $this->site();
        $otherSite = $this->site('other.example.com');
        $operation = SiteFileOperation::create([
            'site_id' => $otherSite->id,
            'user_id' => $user->id,
            'action' => 'file-download',
            'path' => '.env',
            'read_content' => 'DB_PASSWORD=secret',
            'status' => 'finished',
        ]);

        $this->actingAs($user)
            ->get(route('sites.files.download', [$site, $operation]))
            ->assertNotFound();
    }

    private function site(string $domain = 'example.com'): Site
    {
        return Site::create([
            'name' => $domain,
            'domain' => $domain,
            'path' => '/var/www/'.$domain,
            'repository' => 'https://github.com/example/project.git',
            'branch' => 'main',
            'php_version' => '8.3',
            'runtime' => 'laravel',
        ]);
    }
}
