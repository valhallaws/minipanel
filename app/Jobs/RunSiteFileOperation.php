<?php

namespace App\Jobs;

use App\Models\SiteFileOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunSiteFileOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = SiteFileOperation::with('site')->findOrFail($this->operationId);

        try {
            if (! config('minipanel.execution_enabled')) {
                $operation->update([
                    'status' => 'blocked',
                    'output' => 'Ejecución desactivada (MINIPANEL_EXECUTION_ENABLED=false). No se accedió a archivos en este equipo.',
                    'finished_at' => now(),
                ]);

                return;
            }

            $operation->update(['status' => 'running', 'started_at' => now(), 'output' => 'Procesando archivos en el VPS…']);
            $site = $operation->site;
            $command = [
                'sudo', '/usr/local/bin/minipanel-agent', $operation->action,
                $site->path, $site->domain, $site->repository ?? '', $site->branch,
                $site->php_version, $site->queue_enabled ? '1' : '0', $site->scheduler_enabled ? '1' : '0',
                $site->runtime, implode(',', $site->php_extensions ?? []), $operation->path,
            ];

            if ($operation->destination_path !== null) {
                $command[] = $operation->destination_path;
            }

            if ($operation->action === 'file-upload') {
                $command[] = (string) $operation->upload_path;
            }

            if ($operation->action === 'file-download') {
                $command[] = (string) $operation->download_path;
            }

            $process = Process::timeout($this->timeout);

            if ($operation->action === 'file-write') {
                $process = $process->input($operation->content ?? '');
            }

            $result = $process->run($command);
            $output = trim($result->output()."\n".$result->errorOutput());
            $updates = [
                'status' => $result->successful() ? 'finished' : 'failed',
                'output' => (string) str($output ?: 'El agente no devolvió salida.')->limit(8000),
                'finished_at' => now(),
            ];

            if ($result->successful() && $operation->action === 'file-list') {
                $updates['result'] = ['entries' => $this->parseEntries($result->output(), $operation->path)];
            }

            if ($result->successful() && $operation->action === 'file-read') {
                $contents = base64_decode(preg_replace('/\s+/', '', $result->output()) ?: '', true);

                if ($contents === false) {
                    $updates['status'] = 'failed';
                    $updates['output'] = 'El agente devolvió contenido de archivo inválido.';
                } else {
                    $updates['read_content'] = $contents;
                }
            }

            $operation->update($updates);
        } finally {
            if ($operation->upload_path) {
                Storage::disk('local')->delete($operation->upload_path);
                $operation->update(['upload_path' => null]);
            }
        }
    }

    /**
     * @return list<array{name: string, path: string, type: string, size: int, modified_at: int}>
     */
    private function parseEntries(string $output, string $directory): array
    {
        $entries = [];

        foreach (array_filter(explode("\n", trim($output))) as $line) {
            [$type, $name, $size, $modifiedAt] = array_pad(explode("\t", $line, 4), 4, '');

            if (! in_array($type, ['d', 'f'], true) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/', $name)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'path' => $directory === '.' ? $name : $directory.'/'.$name,
                'type' => $type === 'd' ? 'directory' : 'file',
                'size' => max(0, (int) $size),
                'modified_at' => max(0, (int) $modifiedAt),
            ];
        }

        return $entries;
    }

    public function failed(Throwable $exception): void
    {
        SiteFileOperation::whereKey($this->operationId)->update([
            'status' => 'failed',
            'output' => (string) str($exception->getMessage())->limit(8000),
            'finished_at' => now(),
        ]);
    }
}
