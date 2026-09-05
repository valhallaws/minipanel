<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Jobs\RunDnsPropagationCheck;
use App\Models\Deployment;
use App\Models\DnsCheckRun;
use App\Models\Site;
use App\Models\SiteQueue;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SiteManager extends Component
{
    public Site $site;

    public string $environment = '';

    public string $deployCommands = '';

    public string $artisanCommand = '';

    public string $artisanArguments = '';

    public string $alias = '';

    public string $healthUrl = '';

    public string $rollbackCommit = '';

    public string $composerCommand = 'install --no-dev --prefer-dist --optimize-autoloader';

    public string $npmCommand = 'run build';

    public string $dangerConfirmation = '';

    public string $restoreConfirmation = '';

    public string $nginxConfig = '';

    public string $queueName = 'default';

    public string $queueWorkers = '1';

    public string $queueTries = '3';

    public string $queueTimeout = '90';

    public ?string $newWebhookSecret = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->environment = $site->environment ?? '';
        $this->deployCommands = $site->deploy_commands ?? '';
        $this->nginxConfig = $site->nginx_config ?? '';
        $this->healthUrl = $site->health_url ?? 'https://'.$site->domain;
    }

    public function inspect(): void
    {
        $this->queue('inspect');
    }

    public function createDeployKey(bool $rotate = false): void
    {
        $this->queue('create-deploy-key', $rotate ? ['rotate'] : []);
    }

    public function removeDeployKey(): void
    {
        $this->queue('remove-deploy-key');
    }

    public function createWebhook(): void
    {
        $this->newWebhookSecret = Str::random(48);
        $this->site->update(['webhook_token' => Str::random(48), 'webhook_secret' => $this->newWebhookSecret]);
        session()->flash('notice', 'Webhook creado. Copia el secret ahora: por seguridad no se muestra de nuevo.');
    }

    public function disableWebhook(): void
    {
        $this->site->update(['webhook_token' => null, 'webhook_secret' => null]);
        $this->newWebhookSecret = null;
        session()->flash('notice', 'Webhook desactivado.');
    }

    public function backup(): void
    {
        $this->queue('backup');
    }

    public function restoreBackup(): void
    {
        $expected = 'RESTAURAR '.$this->site->domain;
        $this->validate(['restoreConfirmation' => ['required', 'in:'.$expected]]);
        abort_unless(filled($this->site->last_backup_path), 422, 'No hay backup disponible para restaurar.');
        $this->queue('restore-backup', [$this->restoreConfirmation, $this->site->last_backup_path]);
    }

    public function checkDns(): void
    {
        $run = DnsCheckRun::create(['site_id' => $this->site->id]);
        RunDnsPropagationCheck::dispatch($run->id);
        session()->flash('notice', 'Comprobación DNS iniciada.');
    }

    public function cancelDns(int $runId): void
    {
        $this->site->dnsChecks()->whereKey($runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'finished_at' => now()]);
    }

    public function checkHealth(): void
    {
        $this->validate(['healthUrl' => ['required', 'url', 'max:255']]);
        $this->site->update(['health_url' => $this->healthUrl]);
        $this->queue('health', [$this->healthUrl]);
    }

    public function logs(string $target): void
    {
        abort_unless(in_array($target, ['laravel', 'nginx-access', 'nginx-error', 'queue'], true), 422);
        $this->queue('logs', [$target]);
    }

    public function addAlias(): void
    {
        $this->validate(['alias' => ['required', 'lowercase', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/']]);
        abort_if(in_array($this->alias, $this->site->aliases ?? [], true), 422);
        $this->queue('add-alias', [$this->alias]);
    }

    public function removeAlias(string $alias): void
    {
        $this->queue('remove-alias', [$alias]);
    }

    public function restartQueue(): void
    {
        $this->queue('restart-queue');
    }

    public function createQueue(): void
    {
        $data = $this->validate(['queueName' => ['required', 'regex:/^[A-Za-z0-9_-]{1,50}$/'], 'queueWorkers' => ['required', 'integer', 'between:1,12'], 'queueTries' => ['required', 'integer', 'between:1,20'], 'queueTimeout' => ['required', 'integer', 'between:10,3600']]);
        $queue = SiteQueue::updateOrCreate(['site_id' => $this->site->id, 'name' => $data['queueName']], ['workers' => $data['queueWorkers'], 'tries' => $data['queueTries'], 'timeout' => $data['queueTimeout'], 'status' => 'pending']);
        $this->queue('configure-queue', [$queue->name, (string) $queue->workers, (string) $queue->tries, (string) $queue->timeout], ['site_queue_id' => $queue->id]);
    }

    public function removeQueue(int $queueId): void
    {
        $queue = $this->site->queues()->findOrFail($queueId);
        $this->queue('remove-queue', [$queue->name, (string) $queue->workers], ['site_queue_id' => $queue->id]);
    }

    public function rollback(): void
    {
        $this->validate(['rollbackCommit' => ['required', 'regex:/^[a-f0-9]{7,64}$/']]);
        $this->queue('rollback', [$this->rollbackCommit]);
    }

    public function suspendSite(): void
    {
        $this->queue('suspend-site');
    }

    public function resumeSite(): void
    {
        $this->queue('resume-site');
    }

    public function deleteSite(): void
    {
        $expected = 'ELIMINAR '.$this->site->domain;
        $this->validate(['dangerConfirmation' => ['required', 'in:'.$expected]]);
        $this->queue('delete-site', [$this->dangerConfirmation]);
    }

    public function runArtisan(): void
    {
        $this->validate([
            'artisanCommand' => ['required', Rule::in(collect($this->site->artisan_commands ?? [])->pluck('name')->all())],
            'artisanArguments' => ['nullable', 'string', 'max:4000'],
        ]);
        $arguments = array_values(array_filter(preg_split('/\R/', $this->artisanArguments) ?: [], fn ($value) => $value !== ''));
        $this->queue('artisan', array_merge([$this->artisanCommand], $arguments));
    }

    public function composer(string $command): void
    {
        abort_unless(in_array($command, ['install', 'update', 'dump-autoload'], true), 422);
        $this->queue('composer', [$command]);
    }

    public function runComposerCommand(): void
    {
        $this->queue('composer', $this->commandArguments($this->composerCommand, 'composerCommand'));
    }

    public function npm(string $command, ?string $script = null): void
    {
        abort_unless(in_array($command, ['install', 'ci', 'run'], true), 422);
        if ($command === 'run') {
            abort_unless(array_key_exists((string) $script, $this->site->npm_scripts ?? []), 422);
        }
        $this->queue('npm', array_filter([$command, $script]));
    }

    public function runNpmCommand(): void
    {
        $this->queue('npm', $this->commandArguments($this->npmCommand, 'npmCommand'));
    }

    public function saveEnvironment(): void
    {
        $this->validate(['environment' => ['nullable', 'string', 'max:1048576']]);
        $this->site->update(['environment' => $this->environment]);
        $this->queue('write-env');
    }

    public function saveDeployCommands(): void
    {
        $this->validate(['deployCommands' => ['nullable', 'string', 'max:50000']]);

        foreach (preg_split('/\R/', $this->deployCommands) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('/^(?:php artisan|composer|npm)(?:\s+[A-Za-z0-9_.,:@=+\/-]+)*$/', $line)) {
                $this->addError('deployCommands', 'Cada línea debe iniciar con php artisan, composer o npm y no puede contener operadores de shell.');

                return;
            }
        }

        $this->site->update(['deploy_commands' => $this->deployCommands]);
        session()->flash('notice', 'Script post-deploy guardado cifrado. Se ejecutará durante el siguiente deploy.');
    }

    public function saveNginxConfig(): void
    {
        $this->validate(['nginxConfig' => ['nullable', 'string', 'max:50000']]);
        $this->site->update(['nginx_config' => $this->nginxConfig]);
        $this->queue('write-nginx-config');
    }

    private function queue(string $action, array $arguments = [], array $metadata = []): void
    {
        $deployment = Deployment::create([
            'site_id' => $this->site->id, 'user_id' => Auth::id(), 'action' => $action,
            'parameters' => ['arguments' => $arguments, ...$metadata], 'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);
        RunDeployment::dispatch($deployment->id);
        app(AuditLogger::class)->record('operation.queued', $this->site, ['action' => $action, 'deployment_id' => $deployment->id]);
        session()->flash('notice', 'Operación puesta en cola.');
    }

    private function commandArguments(string $command, string $field): array
    {
        $this->validate([$field => ['required', 'string', 'max:2000', 'regex:/^[A-Za-z0-9_.,:@=+\/-]+(?:\s+[A-Za-z0-9_.,:@=+\/-]+)*$/']]);

        return preg_split('/\s+/', trim($command)) ?: [];
    }

    public function render()
    {
        return view('livewire.site-manager', ['activity' => $this->site->deployments()->latest()->take(12)->get(), 'queues' => $this->site->queues()->orderBy('name')->get(), 'dnsRun' => $this->site->dnsChecks()->latest()->first()])
            ->layout('components.layouts.app');
    }
}
