<?php

namespace App\Jobs;

use App\Models\SiteRepository;
use App\Services\DomainGit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class RunRepositorySync implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $repositoryId, public string $token, public ?string $branch = null) {}

    /**
     * Execute the job.
     */
    public function handle(DomainGit $git): void
    {
        $repository = SiteRepository::with('site')->find($this->repositoryId);
        if (! $repository || $repository->operation_token !== $this->token || $repository->status !== 'queued') {
            return;
        }
        try {
            $git->available($repository->site);
            $initialClone = $repository->synced_at === null;
            $steps = $this->plannedSteps($repository, $initialClone);
            $repository->update(['status' => 'running', 'steps' => array_values($steps)]);
            $buffer = '';
            $metadata = null;
            $result = Process::timeout(1740)->input(json_encode([
                'id' => $repository->key_token, 'url' => $repository->url, 'directory' => $repository->directory,
                'branch' => $this->branch,
                'initial' => $initialClone,
                'prepare' => ! $initialClone && $repository->prepare_project,
                'commands' => $initialClone ? '' : $repository->site->deploy_commands ?? '',
            ], JSON_THROW_ON_ERROR))->start($git->command($repository->site, 'sync'), function (string $type, string $output) use (&$buffer, &$metadata, &$steps, $repository): void {
                if ($type !== 'out') {
                    return;
                }
                $buffer .= $output;
                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    $event = json_decode($line, true);
                    if (! is_array($event)) {
                        continue;
                    }
                    if (isset($event['step'], $event['status'])) {
                        $steps[$event['step']] = ['label' => $event['step'], 'status' => $event['status'], 'detail' => mb_substr($event['detail'] ?? '', 0, 3000)];
                        $repository->update(['steps' => array_values($this->sortSteps($steps))]);
                    }
                    if (isset($event['result'])) {
                        $metadata = $event['result'];
                    }
                }
            })->wait();
            if (! $result->successful() || ! is_array($metadata)) {
                throw new RuntimeException(mb_substr(trim($result->errorOutput()) ?: 'El agente no confirmó la finalización. Revisa los pasos y reintenta.', 0, 4000));
            }
            $repository->update(['status' => 'ready', 'branch' => $metadata['branch'], 'branches' => $metadata['branches'], 'commits' => $metadata['commits'], 'project_type' => $metadata['project_type'], 'synced_at' => now(), 'error' => null]);
            if ($this->servesDocumentRoot($repository)) {
                $repository->site->update(['branch' => $metadata['branch']]);
            }
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    private function servesDocumentRoot(SiteRepository $repository): bool
    {
        $directory = trim((string) $repository->directory, '/');
        $documentRoot = trim((string) ($repository->site?->document_root ?? ''), '/');

        return $directory !== '' && ($documentRoot === $directory || str_starts_with($documentRoot.'/', $directory.'/'));
    }

    /**
     * @return array<string, array{label: string, status: string, detail: string}>
     */
    private function plannedSteps(SiteRepository $repository, bool $initialClone): array
    {
        $labels = [
            'Validando clave y conexión',
            'Obteniendo archivos',
            'Actualizando archivos',
            'Detectando el proyecto',
        ];

        if (! $initialClone && $repository->prepare_project) {
            $labels[] = 'Preparando el proyecto';
        }

        $deployCommands = $initialClone ? [] : (preg_split('/\R/', (string) $repository->site?->deploy_commands) ?: []);
        foreach ($deployCommands as $command) {
            $command = trim($command);
            if ($command !== '' && ! str_starts_with($command, '#')) {
                $labels[] = 'Ejecutando script de deploy';
                break;
            }
        }

        return collect($labels)->mapWithKeys(fn (string $label): array => [$label => [
            'label' => $label,
            'status' => 'pending',
            'detail' => '',
        ]])->all();
    }

    /**
     * @param  array<string, array{label: string, status: string, detail: string}>  $steps
     * @return array<string, array{label: string, status: string, detail: string}>
     */
    private function sortSteps(array $steps): array
    {
        $order = array_flip([
            'Validando clave y conexión',
            'Activando mantenimiento Laravel',
            'Obteniendo archivos',
            'Actualizando archivos',
            'Detectando el proyecto',
            'Preparando el proyecto',
            'Ejecutando script de deploy',
            'Reanudando Laravel',
        ]);

        uksort($steps, static function (string $left, string $right) use ($order): int {
            $leftOrder = $order[$left] ?? PHP_INT_MAX;
            $rightOrder = $order[$right] ?? PHP_INT_MAX;

            return $leftOrder === $rightOrder ? $left <=> $right : $leftOrder <=> $rightOrder;
        });

        return $steps;
    }

    public function failed(?Throwable $exception): void
    {
        SiteRepository::whereKey($this->repositoryId)->where('operation_token', $this->token)->update(['status' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'La operación se interrumpió; puedes reintentar.', 0, 4000)]);
    }
}
