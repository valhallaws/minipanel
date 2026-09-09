<?php

namespace Tests\Feature;

use App\Jobs\RunSiteFileOperation;
use App\Livewire\FileManager;
use App\Models\Site;
use App\Models\SiteFileOperation;
use App\Models\User;
use App\Services\SiteFilePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_url_accepts_timeout_from_environment_as_text(): void
    {
        Queue::fake();
        $previous = Env::get('MINIPANEL_FILE_UPLOAD_MAX_TIME');
        Env::getRepository()->set('MINIPANEL_FILE_UPLOAD_MAX_TIME', '120');
        try {
            config()->set('livewire', require base_path('config/livewire.php'));
            Livewire::actingAs(User::factory()->create())->test(FileManager::class, ['site' => $this->site()])
                ->call('_startUpload', 'upload', [['name' => 'archive.zip', 'size' => 50 * 1024 * 1024, 'type' => 'application/zip']], false)
                ->assertDispatched('upload:generatedSignedUrl');
        } finally {
            $previous === null ? Env::getRepository()->clear('MINIPANEL_FILE_UPLOAD_MAX_TIME') : Env::getRepository()->set('MINIPANEL_FILE_UPLOAD_MAX_TIME', (string) $previous);
        }
        Queue::assertNothingPushed();
    }

    public function test_upload_accepts_fifty_mb_and_queues_site_copy(): void
    {
        Queue::fake();
        Storage::fake('local');
        Livewire::actingAs(User::factory()->create())->test(FileManager::class, ['site' => $this->site()])
            ->call('showModal', 'upload')->set('upload', UploadedFile::fake()->create('archive.zip', 51200, 'application/zip'))
            ->call('submitModal')->assertHasNoErrors()->assertSet('modal', '');
        $operation = SiteFileOperation::where('action', 'file-upload')->sole();
        $this->assertSame('archive.zip', $operation->path);
        Queue::assertPushed(RunSiteFileOperation::class, fn ($job) => $job->operationId === $operation->id);
    }

    public function test_file_manager_requires_an_authenticated_user(): void
    {
        $site = $this->site();

        $this->get(route('sites.files', $site))
            ->assertRedirect(route('login'));
    }

    public function test_opening_file_manager_does_not_read_local_files_when_disabled(): void
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
        $this->assertSame('blocked', $operation->status);
        Queue::assertNothingPushed();
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

    public function test_opening_a_directory_renders_its_contents_in_the_same_response(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn ($process) => Process::result(
            str_ends_with(implode(' ', $process->command), ' dist')
                ? "d\tassets\t4096\t1000\nf\tindex.html\t20\t1000\n"
                : "d\tdist\t4096\t1000\n"
        ));
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $site])
            ->call('openDirectory', 'dist')
            ->assertSet('directory', 'dist')
            ->assertSee('index.html')
            ->assertSee("openDirectory('dist/assets')", false);

        $operation = SiteFileOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('file-list', $operation->action);
        $this->assertSame('dist', $operation->path);
        $this->assertSame('finished', $operation->status);
        Queue::assertNothingPushed();
    }

    public function test_returning_to_the_root_directory_lists_it_without_a_worker(): void
    {
        Queue::fake();
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $site])
            ->call('openDirectory', '.')
            ->assertSet('directory', '.');

        $operation = SiteFileOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('file-list', $operation->action);
        $this->assertSame('.', $operation->path);
        Queue::assertNothingPushed();
    }

    public function test_environment_files_can_be_opened_from_file_manager(): void
    {
        Queue::fake();
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $site])
            ->call('openFile', '.env')
            ->assertSet('selectedPath', '.env');

        $operation = SiteFileOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('file-read', $operation->action);
        $this->assertSame('.env', $operation->path);
    }

    public function test_listing_failure_is_visible_without_waiting_for_a_worker(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'Permission denied', exitCode: 1));

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $this->site()])
            ->assertHasErrors('directory')
            ->assertSee('Permission denied')
            ->assertSee('Carpeta no disponible');

        Queue::assertNothingPushed();
    }

    public function test_expanding_the_tree_keeps_the_current_folder_and_loads_children(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn ($process) => Process::result(
            str_ends_with(implode(' ', $process->command), ' bootstrap')
                ? "d\tcache\t4096\t1000\nf\tapp.php\t20\t1000\n"
                : "d\tbootstrap\t4096\t1000\n"
        ));

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $this->site()])
            ->call('toggleDirectory', 'bootstrap')
            ->assertSet('directory', '.')
            ->assertSee("openDirectory('bootstrap/cache')", false)
            ->assertDontSee('app.php')
            ->call('toggleDirectory', 'bootstrap')
            ->assertDontSee("openDirectory('bootstrap/cache')", false);

        Queue::assertNothingPushed();
    }

    public function test_internal_git_and_ssh_directories_remain_blocked(): void
    {
        $this->expectException(HttpException::class);

        app(SiteFilePath::class)->normalize('.git/config', false);
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

    public function test_permissions_modal_rejects_invalid_modes_without_queuing_changes(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $this->site()])
            ->call('selectFile', 'artisan')
            ->call('showModal', 'permissions')
            ->set('permissions', '4777')
            ->call('submitModal')
            ->assertHasErrors('permissions');

        Queue::assertNothingPushed();
    }

    public function test_create_modal_queues_a_non_overwriting_create_operation(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $this->site()])
            ->call('showModal', 'create')
            ->set('newName', 'hello.html')
            ->call('submitModal')
            ->assertSet('modal', '');

        $operation = SiteFileOperation::query()->where('action', 'file-create')->sole();
        $this->assertSame('hello.html', $operation->path);
        Queue::assertPushed(RunSiteFileOperation::class, fn (RunSiteFileOperation $job): bool => $job->operationId === $operation->id);
    }

    public function test_editor_reads_and_saves_directly_and_stays_open(): void
    {
        Queue::fake();
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn ($process) => Process::result(
            in_array('file-read', $process->command, true) ? base64_encode('Original text') : ''
        ));

        Livewire::actingAs(User::factory()->create())
            ->test(FileManager::class, ['site' => $this->site()])
            ->call('openFile', 'hello.txt')
            ->assertSet('editing', true)
            ->assertSet('contents', 'Original text')
            ->set('contents', 'Updated text')
            ->call('saveFile')
            ->assertSet('editing', true)
            ->assertSet('contents', 'Updated text')
            ->assertSee('Archivo guardado.');

        $this->assertSame('Updated text', SiteFileOperation::query()->where('action', 'file-write')->sole()->content);
        $this->assertSame('Archivo leído.', SiteFileOperation::query()->where('action', 'file-read')->sole()->output);
        Queue::assertNothingPushed();
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
