<?php

namespace Tests\Feature;

use App\Jobs\RunDatabaseOperation;
use App\Livewire\DatabaseManager;
use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use App\Models\User;
use App\Services\DatabaseUploads;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_waits_for_confirmation_and_cancel_does_not_dispatch(): void
    {
        Queue::fake();
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->call('prepareOperation', 'delete-database', $database->id)->assertSet('modal', 'operation')
            ->call('closeModal');

        $this->assertModelExists($database);
        $this->assertDatabaseCount('site_database_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_confirmed_export_queues_the_exact_database(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Queue::fake();
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->call('prepareOperation', 'export', $database->id)->call('confirmOperation')->assertHasNoErrors()->assertSet('modal', null);

        $operation = SiteDatabaseOperation::query()->sole();
        $this->assertSame('export', $operation->action);
        $this->assertSame('orion', $operation->resource_name);
        Queue::assertPushed(RunDatabaseOperation::class, fn ($job) => $job->operationId === $operation->id);
    }

    public function test_operations_cannot_target_another_domain(): void
    {
        Queue::fake();
        $database = $this->site('other.example.com')->databases()->create(['name' => 'private_db']);
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->call('prepareOperation', 'delete-database', $database->id);
    }

    public function test_import_requires_sql_file_and_stores_it_privately(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        Queue::fake();
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site]);
        $component->call('prepareOperation', 'import', $database->id)->call('confirmOperation')->assertHasErrors('uploadToken');
        $upload = $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'dump.sql', 'size' => 9])->assertOk()->json();
        $this->post($upload['url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'SELECT 1;')])->assertOk();
        $component->set('uploadToken', $upload['token'])
            ->call('confirmOperation')->assertHasNoErrors();

        $operation = SiteDatabaseOperation::query()->sole();
        Storage::disk('local')->assertExists($operation->upload_path);
        Queue::assertPushed(RunDatabaseOperation::class);
    }

    public function test_upload_chunks_do_not_exhaust_the_limit_for_starting_another_upload(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $this->actingAs(User::factory()->create());
        $upload = $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'dump.sql', 'size' => 12])->assertOk()->json();

        for ($offset = 0; $offset < 12; $offset++) {
            $this->post($upload['url'], ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'a')])->assertOk();
        }

        $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'next.sql', 'size' => 9])->assertOk();
        $this->assertSame('aaaaaaaaaaaa', Storage::disk('local')->get('database-imports/'.$upload['token'].'.part'));
    }

    public function test_deleting_database_preserves_users_without_broadening_access(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Done'));
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);
        $user = $site->databaseUsers()->create(['name' => 'orion_user', 'password' => 'LongPassword123456', 'site_database_id' => $database->id, 'all_databases' => false]);
        $operation = $this->operation($site, 'delete-database', $database->id, 'orion');

        (new RunDatabaseOperation($operation->id))->handle();

        $this->assertModelMissing($database);
        $this->assertNull($user->fresh()->site_database_id);
        $this->assertFalse($user->fresh()->all_databases);
        $this->assertSame('finished', $operation->fresh()->status);
        Process::assertRan(fn ($process) => json_decode($process->input, true)['operation'] === 'delete-database');
    }

    public function test_failed_delete_preserves_record_and_hides_process_output(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'secret', exitCode: 1));
        $site = $this->site();
        $user = $site->databaseUsers()->create(['name' => 'orion_user', 'password' => 'LongPassword123456']);
        $operation = $this->operation($site, 'delete-user', $user->id, 'orion_user');

        (new RunDatabaseOperation($operation->id))->handle();

        $this->assertModelExists($user);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertStringNotContainsString('secret', $operation->fresh()->message);
    }

    public function test_import_payload_is_scoped_and_upload_is_removed_after_processing(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        $streamed = [];
        Process::fake(function ($process) use (&$streamed) {
            $streamed = iterator_to_array($process->input);

            return Process::result('Done');
        });
        Storage::disk('local')->put('database-imports/test.sql', 'SELECT 1;');
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);
        $operation = $this->operation($site, 'import', $database->id, 'orion');
        $operation->update(['upload_path' => 'database-imports/test.sql']);

        (new RunDatabaseOperation($operation->id))->handle();

        Storage::disk('local')->assertMissing('database-imports/test.sql');
        $this->assertSame('finished', $operation->fresh()->status);
        $this->assertSame(9, json_decode($streamed[0], true)['size']);
        $this->assertSame('SELECT 1;', implode('', array_slice($streamed, 1)));
        Process::assertRan(fn ($process) => $process->timeout === 7200);
    }

    public function test_download_rejects_other_domain_and_non_export_operations(): void
    {
        $site = $this->site();
        $operation = $this->operation($site, 'import', 1, 'orion');
        $this->actingAs(User::findOrFail($operation->user_id));

        $this->get(route('sites.databases.download', [$site, $operation]))->assertNotFound();
        $this->get(route('sites.databases.download', [$this->site('other.example.com'), $operation]))->assertNotFound();
    }

    private function operation(Site $site, string $action, int $id, string $name): SiteDatabaseOperation
    {
        return SiteDatabaseOperation::factory()->create(['site_id' => $site->id, 'action' => $action, 'resource_id' => $id, 'resource_name' => $name]);
    }

    public function test_upload_accepts_one_gib_and_rejects_larger_files(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $this->actingAs(User::factory()->create());

        $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'large.sql', 'size' => 1073741824])->assertOk();
        $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'large.sql', 'size' => 1073741825])->assertUnprocessable();
    }

    public function test_chunks_are_ordered_owned_and_incomplete_upload_cannot_be_claimed(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $user = User::factory()->create();
        $this->actingAs($user);
        $upload = $this->postJson(route('sites.database-uploads.start', $site), ['name' => 'dump.sql', 'size' => 18])->json();
        $chunk = UploadedFile::fake()->createWithContent('chunk.bin', 'SELECT 1;');

        $this->postJson($upload['url'], ['offset' => 9, 'chunk' => $chunk])->assertConflict();
        $this->postJson($upload['url'], ['offset' => 0, 'chunk' => $chunk])->assertOk();
        $this->actingAs(User::factory()->create())->postJson($upload['url'], ['offset' => 9, 'chunk' => $chunk])->assertNotFound();

        $this->assertSame('SELECT 1;', Storage::disk('local')->get('database-imports/'.$upload['token'].'.part'));
        $this->expectException(ValidationException::class);
        app(DatabaseUploads::class)->claim($upload['token'], $site->id, $user->id);
    }

    public function test_one_gib_import_is_sent_in_bounded_chunks(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Storage::fake('local');
        Storage::disk('local')->put('database-imports/large.sql', '');
        $file = fopen(Storage::disk('local')->path('database-imports/large.sql'), 'wb');
        ftruncate($file, 1073741824);
        fclose($file);
        $bytes = 0;
        $largest = 0;
        Process::fake(function ($process) use (&$bytes, &$largest) {
            foreach ($process->input as $index => $chunk) {
                if ($index > 0) {
                    $bytes += strlen($chunk);
                    $largest = max($largest, strlen($chunk));
                }
            }

            return Process::result('Done');
        });
        $site = $this->site();
        $database = $site->databases()->create(['name' => 'orion']);
        $operation = $this->operation($site, 'import', $database->id, 'orion');
        $operation->update(['upload_path' => 'database-imports/large.sql']);

        (new RunDatabaseOperation($operation->id))->handle();

        $this->assertSame(1073741824, $bytes);
        $this->assertSame(1048576, $largest);
        $this->assertSame('finished', $operation->fresh()->status);
        Storage::disk('local')->assertMissing('database-imports/large.sql');
    }

    public function test_zip_is_deleted_after_response_and_cannot_be_downloaded_twice(): void
    {
        Storage::fake('local');
        config()->set('minipanel.database_exports_path', Storage::disk('local')->path('exports'));
        $site = $this->site();
        $operation = $this->operation($site, 'export', 1, 'orion');
        $operation->update(['status' => 'finished']);
        $path = 'exports/'.$operation->token.'/dump.zip';
        Storage::disk('local')->put($path, 'zip-test-content');
        $this->actingAs(User::findOrFail($operation->user_id));

        $response = $this->get(route('sites.databases.download', [$site, $operation]))->assertDownload('orion.zip');
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('downloaded', $operation->fresh()->status);
        $this->get(route('sites.databases.download', [$site, $operation]))->assertNotFound();
    }

    private function site(string $domain = 'example.com'): Site
    {
        return Site::create(['name' => 'Example', 'domain' => $domain, 'path' => '/var/www/'.$domain, 'runtime' => 'static', 'status' => 'active', 'php_version' => '8.3']);
    }
}
