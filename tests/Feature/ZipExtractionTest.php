<?php

namespace Tests\Feature;

use App\Jobs\RunSiteFileOperation;
use App\Livewire\FileManager;
use App\Models\Site;
use App\Models\SiteFileOperation;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class ZipExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function archive(array $entries): string
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('');
        $zip = new ZipArchive;
        $zip->open($root.'source.zip', ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $root;
    }

    private function extract(string $root, string $mode = 'skip', string $destination = '.'): ProcessResult
    {
        return Process::run(['python3', '-I', base_path('ops-agent/minipanel-extract.py'), rtrim($root, '/'), 'source.zip', $destination, $mode]);
    }

    public function test_extract_creates_directories_preserves_zip_and_protects_env(): void
    {
        $root = $this->archive(['assets/app.js' => 'hello', '.env' => 'private']);
        $hash = hash_file('sha256', $root.'source.zip');
        $result = $this->extract($root, 'skip', 'output');
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('hello', file_get_contents($root.'output/assets/app.js'));
        $this->assertSame(0600, fileperms($root.'output/.env') & 0777);
        $this->assertSame($hash, hash_file('sha256', $root.'source.zip'));
    }

    public function test_skip_and_replace_respect_existing_files(): void
    {
        $root = $this->archive(['index.html' => 'new']);
        Storage::disk('local')->put('index.html', 'old');
        $this->assertTrue($this->extract($root)->successful());
        $this->assertSame('old', file_get_contents($root.'index.html'));
        $this->assertTrue($this->extract($root, 'replace')->successful());
        $this->assertSame('new', file_get_contents($root.'index.html'));
    }

    #[TestWith(['../escape.txt'])]
    #[TestWith(['/tmp/escape.txt'])]
    #[TestWith(['.ssh/authorized_keys'])]
    #[TestWith(['.git/config'])]
    public function test_unsafe_archive_paths_are_rejected_before_writing(string $name): void
    {
        $root = $this->archive(['safe.txt' => 'safe', $name => 'bad']);
        $this->assertFalse($this->extract($root)->successful());
        $this->assertFileDoesNotExist($root.'safe.txt');
    }

    public function test_existing_symlink_is_not_followed(): void
    {
        $root = $this->archive(['linked/file.txt' => 'bad']);
        Storage::disk('local')->makeDirectory('outside');
        symlink($root.'outside', $root.'linked');
        $this->assertFalse($this->extract($root, 'replace')->successful());
        $this->assertFileDoesNotExist($root.'outside/file.txt');
    }

    public function test_symlink_inside_archive_is_rejected(): void
    {
        $root = $this->archive(['link' => '/tmp']);
        $zip = new ZipArchive;
        $zip->open($root.'source.zip');
        $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->assertFalse($this->extract($root)->successful());
        $this->assertFalse(is_link($root.'link'));
    }

    public function test_archive_cannot_replace_itself(): void
    {
        $root = $this->archive(['source.zip' => 'bad']);
        $hash = hash_file('sha256', $root.'source.zip');
        $this->assertFalse($this->extract($root, 'replace')->successful());
        $this->assertSame($hash, hash_file('sha256', $root.'source.zip'));
    }

    public function test_extract_modal_queues_destination_and_policy_for_agent(): void
    {
        Queue::fake();
        $site = Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'branch' => 'main', 'php_version' => '8.3', 'runtime' => 'static']);
        Livewire::actingAs(User::factory()->create())->test(FileManager::class, ['site' => $site])
            ->set('selectedPath', 'httpdocs/source.zip')->call('showModal', 'extract')
            ->set('moveDirectory', 'httpdocs/new')->set('extractMode', 'replace')->call('submitModal')
            ->assertHasNoErrors()->assertSet('modal', '');
        $operation = SiteFileOperation::where('action', 'file-extract')->sole();
        $this->assertSame('httpdocs/new', $operation->destination_path);
        $this->assertSame('replace', $operation->content);
        Queue::assertPushed(RunSiteFileOperation::class, fn ($job) => $job->operationId === $operation->id);
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Extracted'));
        (new RunSiteFileOperation($operation->id))->handle();
        Process::assertRan(fn ($process) => array_slice($process->command, -3) === ['httpdocs/source.zip', 'httpdocs/new', 'replace']);
        $this->assertSame('finished', $operation->fresh()->status);
    }
}
