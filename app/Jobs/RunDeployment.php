<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\ServerSetting;
use App\Models\Site;
use App\Models\SiteQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Throwable;

class RunDeployment implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 30;

    public function __construct(public int $deploymentId) {}

    public function handle(): void
    {
        $deployment = Deployment::with('site')->findOrFail($this->deploymentId);
        if ($deployment->status === 'cancelled') {
            return;
        }
        $site = $deployment->site;

        if ($site?->repositories()->whereIn('status', ['queued', 'running'])->exists()) {
            $deployment->update(['status' => 'blocked', 'output' => 'Espera a que termine Git antes de operar el dominio.', 'finished_at' => now()]);

            return;
        }

        if (! $site || $site->trashed() || $site->lifecycle_action || $deployment->action === 'delete-site') {
            $deployment->update(['status' => 'blocked', 'output' => 'Dominio no disponible o acción antigua deshabilitada.', 'finished_at' => now()]);

            return;
        }

        if (! config('minipanel.execution_enabled')) {
            $deployment->update([
                'status' => 'blocked',
                'output' => 'Ejecución desactivada (MINIPANEL_EXECUTION_ENABLED=false). No se ejecutó ninguna acción en este equipo.',
                'finished_at' => now(),
            ]);

            return;
        }

        if (in_array($deployment->action, ['configure-hosting', 'issue-ssl'], true) && $site->status !== 'active') {
            $deployment->update(['status' => 'blocked', 'output' => 'El dominio debe estar activo.', 'finished_at' => now()]);

            return;
        }

        $deployment->update(['status' => 'running', 'started_at' => now(), 'output' => 'Ejecutando agente…']);

        $parameters = $deployment->parameters ?? [];
        $arguments = $parameters['arguments'] ?? [];
        if ($deployment->action === 'issue-ssl') {
            $arguments = [ServerSetting::first()?->public_ip ?? '', $parameters['certificate_email'] ?? ''];
        }
        $command = [
            'sudo', '/usr/local/bin/minipanel-agent', $deployment->action,
            $site->path, $site->resourceDomain(), $site->repository ?? '', $site->branch,
            $site->php_version, $site->queue_enabled ? '1' : '0', $site->scheduler_enabled ? '1' : '0',
            $site->runtime,
            implode(',', $site->php_extensions ?? []),
            ...$arguments,
        ];
        $process = Process::timeout($this->timeout);
        if ($deployment->action === 'write-env') {
            $process = $process->input($site->environment ?? '');
        }
        if ($deployment->action === 'write-nginx-config') {
            $process = $process->input($site->nginx_config ?? '');
        }
        if ($deployment->action === 'deploy') {
            $process = $process->input($site->deploy_commands ?? '');
        }
        $result = $process->run($command);

        $output = trim($result->output()."\n".$result->errorOutput());
        if ($deployment->action === 'issue-ssl' && $result->exitCode() === 75) {
            $deployment->update([
                'status' => 'queued',
                'output' => (string) str($output ?: 'Esperando propagación DNS antes de emitir SSL.')->limit(8000),
                'started_at' => null,
            ]);
            $this->release(120);

            return;
        }
        if ($deployment->action === 'inspect' && $result->successful()) {
            $this->saveInspection($site, $output);
            $output = 'Inspección actualizada: runtime, comandos Artisan, scripts NPM y estado del proyecto.';
        }
        if ($deployment->action === 'create-deploy-key' && $result->successful()) {
            $site->update(['deploy_key_public' => trim($result->output())]);
            $output = 'Llave SSH creada. Agrega la llave pública al proveedor Git antes de desplegar.';
        }
        if ($deployment->action === 'remove-deploy-key' && $result->successful()) {
            $site->update(['deploy_key_public' => null]);
        }
        if ($deployment->action === 'backup' && $result->successful()) {
            $site->update(['last_backup_at' => now(), 'last_backup_path' => trim($result->output())]);
        }
        if ($deployment->action === 'verify-backup' && $result->successful()) {
            $site->update(['backup_last_verified_at' => now()]);
        }
        if ($deployment->action === 'prune-backups' && $result->successful()) {
            $this->markExpiredBackups($site);
        }
        if ($deployment->action === 'health') {
            $output = $this->recordHealthCheck($site, $output, $result->successful());
        }
        if ($deployment->action === 'dns-check' && $result->successful()) {
            $site->update(['dns_results' => json_decode($result->output(), true) ?? [], 'dns_checked_at' => now()]);
            $output = 'Propagación DNS actualizada.';
        }
        if (in_array($deployment->action, ['add-alias', 'remove-alias'], true) && $result->successful()) {
            $alias = $parameters['arguments'][0] ?? '';
            $aliases = $site->aliases ?? [];
            $aliases = $deployment->action === 'add-alias' ? [...$aliases, $alias] : array_values(array_diff($aliases, [$alias]));
            $site->update(['aliases' => array_values(array_unique($aliases))]);
        }
        if ($deployment->action === 'configure-queue' && $result->successful()) {
            SiteQueue::whereKey($parameters['site_queue_id'] ?? null)->update(['status' => 'active']);
        }
        if ($deployment->action === 'remove-queue' && $result->successful()) {
            SiteQueue::whereKey($parameters['site_queue_id'] ?? null)->delete();
        }
        $storedOutput = $output ?: 'El agente no devolvió salida.';
        if (! $result->successful() && mb_strlen($storedOutput) > 8000) {
            $storedOutput = "… Se muestran los últimos 8,000 caracteres del error.\n".mb_substr($storedOutput, -8000);
        }

        $deployment->update([
            'status' => $result->successful() ? 'finished' : 'failed',
            'output' => (string) str($storedOutput)->limit(8000),
            'finished_at' => now(),
        ]);

        if ($result->successful()) {
            $updates = match ($deployment->action) {
                'provision' => ['status' => 'active', 'last_deployed_at' => now()],
                'deploy' => ['last_deployed_at' => now()],
                'issue-ssl' => ['ssl_enabled' => true],
                'configure-hosting' => [
                    'document_root' => $arguments[0],
                    'php_version' => $arguments[1],
                    'node_version' => $arguments[5] ?? $site->node_version ?? '22',
                    'hosting_limits' => [
                        'upload_limit' => $arguments[2] ?? '2g',
                        'memory_limit' => $arguments[3] ?? '256M',
                        'execution_timeout' => (int) ($arguments[4] ?? 120),
                    ],
                ],
                'suspend-site' => ['status' => 'suspended'],
                'resume-site' => ['status' => 'active'],
                'write-env' => [],
                default => [],
            };
            $site->update($updates);
            if ($deployment->action === 'issue-ssl') {
                $site->update(['ssl_auto_renew' => str_contains($output, 'MINIPANEL_RENEWAL_ENABLED')]);
            }
        }
        if ($deployment->action === 'delete-site' && $result->successful()) {
            $site->delete();
        }

        if ($result->successful() && $deployment->action === 'backup') {
            $this->queueFollowUp($site, 'verify-backup', [trim($result->output())]);
        }
        if ($result->successful() && $deployment->action === 'verify-backup') {
            $this->queueFollowUp($site, 'prune-backups', [(string) $site->backup_retention]);
        }
    }

    private function queueFollowUp(Site $site, string $action, array $arguments): void
    {
        $deployment = Deployment::create([
            'site_id' => $site->id,
            'action' => $action,
            'parameters' => ['arguments' => $arguments, 'automatic' => true],
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);

        self::dispatch($deployment->id);
    }

    private function markExpiredBackups(Site $site): void
    {
        $site->deployments()
            ->where('action', 'backup')
            ->where('status', 'finished')
            ->latest('finished_at')
            ->skip($site->backup_retention)
            ->each(function (Deployment $deployment): void {
                $deployment->update([
                    'status' => 'pruned',
                    'output' => 'Eliminado por la política de retención de respaldos.',
                ]);
            });
    }

    private function recordHealthCheck(Site $site, string $output, bool $agentSucceeded): string
    {
        $result = json_decode($output, true);
        $statuses = ['healthy', 'degraded', 'failed'];
        $status = is_array($result) && in_array($result['status'] ?? null, $statuses, true)
            ? $result['status']
            : 'failed';
        $statusCode = filter_var($result['status_code'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 599]]);
        $responseTime = filter_var($result['response_time_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $sslExpiresAt = filter_var($result['ssl_expires_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $error = is_array($result) && is_string($result['error'] ?? null) && $result['error'] !== ''
            ? str($result['error'])->limit(255)->toString()
            : null;

        if (! $agentSucceeded || ! is_array($result)) {
            $error = str($output ?: 'El agente no devolvió una respuesta válida.')->squish()->limit(255)->toString();
        }

        $site->healthChecks()->create([
            'status' => $status,
            'status_code' => $statusCode === false ? null : $statusCode,
            'response_time_ms' => $responseTime === false ? null : $responseTime,
            'error' => $error,
            'checked_at' => now(),
        ]);
        $site->update([
            'health_status' => $status,
            'health_checked_at' => now(),
            'ssl_expires_at' => $sslExpiresAt === false ? null : now()->setTimestamp($sslExpiresAt),
        ]);

        $summary = 'Disponibilidad: '.match ($status) {
            'healthy' => 'activa',
            'degraded' => 'lenta',
            default => 'sin respuesta',
        };
        if ($statusCode !== false) {
            $summary .= ' · HTTP '.$statusCode;
        }
        if ($responseTime !== false) {
            $summary .= ' · '.$responseTime.' ms';
        }
        if ($error) {
            $summary .= ' · '.$error;
        }

        return $summary;
    }

    private function saveInspection(Site $site, string $output): void
    {
        preg_match('/__MINIPANEL_ARTISAN__\s*(.*?)\s*__MINIPANEL_NPM__/s', $output, $artisanMatch);
        preg_match('/__MINIPANEL_NPM__\s*(.*?)\s*__MINIPANEL_ENV__/s', $output, $npmMatch);
        preg_match('/__MINIPANEL_ENV__\s*(.*?)\s*__MINIPANEL_SERVICES__/s', $output, $environmentMatch);
        preg_match('/__MINIPANEL_SERVICES__\s*(.*)$/s', $output, $serviceMatch);
        $artisan = json_decode($artisanMatch[1] ?? '{}', true);
        $commands = collect($artisan['commands'] ?? [])->map(fn ($command) => [
            'name' => $command['name'] ?? '', 'description' => $command['description'] ?? '',
        ])->filter(fn ($command) => $command['name'] !== '')->values()->all();
        $npmScripts = json_decode($npmMatch[1] ?? '{}', true);
        $environment = base64_decode(trim($environmentMatch[1] ?? ''), true);
        $services = json_decode($serviceMatch[1] ?? '{}', true);
        $site->update([
            'runtime' => $commands !== [] ? 'laravel' : (is_array($npmScripts) && $npmScripts !== [] ? 'node' : 'static'),
            'artisan_commands' => $commands,
            'npm_scripts' => is_array($npmScripts) ? $npmScripts : [],
            'environment' => $environment === false ? $site->environment : $environment,
            'last_inspected_at' => now(),
            'queue_status' => $services['queue'] ?? 'unknown',
            'scheduler_status' => $services['scheduler'] ?? 'unknown',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Deployment::whereKey($this->deploymentId)->update([
            'status' => 'failed', 'output' => (string) str($exception->getMessage())->limit(8000), 'finished_at' => now(),
        ]);
    }
}
