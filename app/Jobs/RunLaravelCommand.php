<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DomainGit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class RunLaravelCommand implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $deploymentId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $operation = Deployment::with('site')->findOrFail($this->deploymentId);
        if ($operation->status !== 'queued') {
            return;
        }
        try {
            $site = $operation->site;
            if (! $site || $site->trashed()) {
                throw new RuntimeException('Dominio no disponible.');
            }
            app(DomainGit::class)->available($site);
            if ($site->repositories()->whereIn('status', ['queued', 'running'])->exists()) {
                throw new RuntimeException('Espera a que termine Git.');
            }
            $parameters = $operation->parameters;
            $repositoryId = (int) $parameters['repository_id'];
            $directory = $repositoryId ? $site->repositories()->where('project_type', 'Laravel')->findOrFail($repositoryId)->directory : ($site->runtime === 'laravel' ? '.' : null);
            if (! $directory) {
                throw new RuntimeException('No se encontró el proyecto Laravel.');
            }
            $operation->update(['status' => 'running', 'started_at' => now(), 'output' => 'Ejecutando en /'.$directory.'…']);
            if (isset($parameters['service'])) {
                $command = ['sudo', '/usr/local/bin/minipanel-agent', 'laravel-service', $site->path, $site->resourceDomain(), '', 'main', $site->php_version, '0', '0', 'static', '', (string) $repositoryId, $directory, $parameters['service'], $parameters['enabled'] ? '1' : '0', $parameters['name'] ?? 'default', (string) ($parameters['workers'] ?? 1), (string) ($parameters['tries'] ?? 3), (string) ($parameters['timeout'] ?? 60)];
                $result = Process::timeout(90)->run($command);
                $output = trim($result->output()."\n".$result->errorOutput());
            } else {
                $buffer = '';
                $output = '';
                $task = $parameters['task'] ?? null;
                $action = $task === 'puppeteer' ? 'laravel-puppeteer' : 'laravel-command';
                $payload = $task === 'puppeteer'
                    ? ['directory' => $directory]
                    : ['directory' => $directory, 'tool' => $parameters['tool'], 'arguments' => $parameters['arguments']];
                $timeout = $task === 'puppeteer' ? 1_710 : 870;
                $result = Process::timeout($timeout)->input(json_encode($payload, JSON_THROW_ON_ERROR))->start(app(DomainGit::class)->command($site, $action), function (string $type, string $chunk) use (&$buffer, &$output, $operation): void {
                    if ($type !== 'out') {
                        return;
                    }
                    $buffer .= $chunk;
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $event = json_decode(substr($buffer, 0, $end), true);
                        $buffer = substr($buffer, $end + 1);
                        if (isset($event['output'])) {
                            $output = $event['output'];
                        } elseif (! empty($event['detail'])) {
                            $output = $event['detail'];
                            $operation->update(['output' => mb_substr($output, -16000)]);
                        }
                    }
                })->wait();
                if (! $result->successful()) {
                    $output .= "\n".$result->errorOutput();
                }
            }
            $operation->update(['status' => $result->successful() ? 'finished' : 'failed', 'output' => mb_substr(trim($output) ?: 'Operación completada.', -16000), 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Deployment::whereKey($this->deploymentId)->update(['status' => 'failed', 'output' => mb_substr($exception?->getMessage() ?? 'La operación no pudo completarse.', 0, 8000), 'finished_at' => now()]);
    }
}
