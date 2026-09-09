<?php

namespace App\Jobs;

use App\Models\SiteDatabaseOperation;
use App\Services\AuditLogger;
use App\Services\DatabaseStatistics;
use App\Services\DatabaseUploads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunDatabaseOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7230;

    public function __construct(public int $operationId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $operation = SiteDatabaseOperation::with('site')->findOrFail($this->operationId);
        if (! SiteDatabaseOperation::whereKey($operation->id)->where('status', 'pending')->update(['status' => 'running'])) {
            return;
        }
        try {
            Cache::lock('domain-databases', 7250)->block(2, function () use ($operation): void {
                $site = $operation->site;
                if (! config('minipanel.execution_enabled') || ! $site || $site->lifecycle_action || $site->status !== 'active') {
                    throw new \RuntimeException('Execution unavailable');
                }
                $resource = $operation->action === 'delete-user'
                    ? $site->databaseUsers()->findOrFail($operation->resource_id)
                    : $site->databases()->findOrFail($operation->resource_id);
                if ($resource->name !== $operation->resource_name) {
                    throw new \RuntimeException('Resource changed');
                }
                $payload = ['operation' => $operation->action, 'name' => $resource->name, 'token' => $operation->token];
                $input = json_encode($payload, JSON_THROW_ON_ERROR)."\n";
                if ($operation->action === 'import') {
                    $size = Storage::disk('local')->size($operation->upload_path);
                    if ($size < 1 || $size > DatabaseUploads::MAX_BYTES) {
                        throw new \RuntimeException('Invalid SQL file');
                    }
                    $payload['size'] = $size;
                    $input = $this->streamImport($operation, $payload, $size);
                }
                $result = Process::timeout(7200)->input($input)->run([
                    'sudo', '/usr/local/bin/minipanel-agent', 'database-sync', $site->path, $site->resourceDomain(),
                    '', 'main', $site->php_version, '0', '0', 'static', '',
                ]);
                if (! $result->successful()) {
                    throw new \RuntimeException('Agent failed');
                }
                if ($operation->action === 'delete-database') {
                    DB::transaction(function () use ($site, $resource): void {
                        $site->databaseUsers()->where('site_database_id', $resource->id)->update(['site_database_id' => null, 'all_databases' => false]);
                        $resource->delete();
                    });
                } elseif ($operation->action === 'delete-user') {
                    $resource->delete();
                }
                $operation->update(['status' => 'finished', 'message' => 'Operación completada.']);
                app(AuditLogger::class)->record('database.'.$operation->action, $site);
            });
        } catch (Throwable $exception) {
            $this->failed($exception);
        } finally {
            DatabaseStatistics::forget($operation->site);
            if ($operation->upload_path) {
                Storage::disk('local')->delete($operation->upload_path);
                $operation->update(['upload_path' => null]);
            }
        }
    }

    private function streamImport(SiteDatabaseOperation $operation, array $payload, int $size): \Generator
    {
        $stream = Storage::disk('local')->readStream($operation->upload_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Upload unavailable');
        }
        try {
            yield json_encode($payload, JSON_THROW_ON_ERROR)."\n";
            $sent = 0;
            $reported = -1;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Read failed');
                }
                $sent += strlen($chunk);
                yield $chunk;
                $percent = min(100, (int) floor($sent * 100 / $size));
                if ($percent >= $reported + 5 || $percent === 100) {
                    $operation->update(['message' => $percent === 100 ? 'SQL enviado. Esperando que MariaDB termine de ejecutarlo…' : 'Enviando SQL a MariaDB: '.$percent.'%']);
                    $reported = $percent;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = SiteDatabaseOperation::find($this->operationId);
        if ($operation?->upload_path) {
            Storage::disk('local')->delete($operation->upload_path);
        }
        $operation?->update(['status' => 'failed', 'upload_path' => null, 'message' => 'No se completó. Revisa el servidor o el SQL. Una importación puede quedar parcialmente aplicada; no se reintenta automáticamente.']);
    }
}
