<?php

namespace App\Livewire;

use App\Jobs\CaptureSiteSnapshot;
use App\Jobs\RunDeployment;
use App\Jobs\RunDnsPropagationCheck;
use App\Models\Deployment;
use App\Models\DnsCheckRun;
use App\Models\ServerSetting;
use App\Models\Site;
use App\Models\SiteQueue;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SiteManager extends Component
{
    public Site $site;

    public bool $embedded = false;

    public bool $laravelOpened = false;

    public bool $sslOpened = false;

    public bool $filesOpened = false;

    public bool $databasesOpened = false;

    public bool $gitOpened = false;

    public ?int $restoreBackupDeploymentId = null;

    public array $siteStatistics = [];

    public function initializeSiteSummary(): void
    {
        $this->refreshSnapshot();
        $this->loadSiteStatistics();
    }

    public function loadSiteStatistics(bool $refresh = false): void
    {
        abort_unless(Auth::check(), 403);
        if ($this->site->fresh()?->status !== 'active' || ! config('minipanel.execution_enabled')) {
            return;
        }

        $cacheKey = 'site-statistics-'.$this->site->id;
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $this->siteStatistics = Cache::remember($cacheKey, now()->addMinutes(5), function (): array {
            try {
                $result = Process::timeout(12)->run([
                    'sudo', '/usr/local/bin/minipanel-agent', 'site-statistics', $this->site->path, $this->site->resourceDomain(),
                    '', 'main', $this->site->php_version, '0', '0', 'static', '',
                ]);
                $statistics = json_decode($result->output(), true);
                if (! $result->successful() || ! is_array($statistics) || ! is_int($statistics['disk_bytes'] ?? null) || ! is_int($statistics['traffic_bytes'] ?? null) || ! is_bool($statistics['traffic_available'] ?? null)) {
                    return [];
                }

                return [
                    'disk' => $this->formatBytes($statistics['disk_bytes']),
                    'traffic' => $this->formatBytes($statistics['traffic_bytes']),
                    'traffic_available' => $statistics['traffic_available'],
                ];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function openLaravel(): void
    {
        abort_unless(Auth::check(), 403);
        $this->laravelOpened = true;
    }

    public function openSsl(): void
    {
        abort_unless(Auth::check(), 403);
        $this->sslOpened = true;
    }

    public function openFiles(): void
    {
        abort_unless(Auth::check(), 403);
        $this->filesOpened = true;
    }

    public function openDatabases(): void
    {
        abort_unless(Auth::check(), 403);
        $this->databasesOpened = true;
    }

    public function openGit(): void
    {
        abort_unless(Auth::check(), 403);
        $this->gitOpened = true;
    }

    public function refreshSnapshot(bool $force = false): void
    {
        abort_unless(Auth::check(), 403);
        if ($this->site->fresh()->status !== 'active' || ! config('minipanel.execution_enabled')) {
            return;
        }
        if (! $force && Cache::has('site-snapshot-'.$this->site->id)) {
            return;
        }
        if (! Cache::add('site-snapshot-request-'.$this->site->id, true, 60)) {
            return;
        }
        Cache::put('site-snapshot-'.$this->site->id, ['status' => 'queued'], now()->addMinutes(2));
        CaptureSiteSnapshot::dispatch($this->site->id);
    }

    public string $documentRoot = '.';

    public string $hostingPhpVersion = '8.3';

    public string $hostingNodeVersion = '22';

    public string $hostingUploadLimit = '2g';

    public string $hostingMemoryLimit = '256M';

    public string $hostingExecutionTimeout = '120';

    public bool $acceptCertificateTerms = false;

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

    public function mount(Site $site, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->site = $site;
        $this->documentRoot = $site->document_root ?? ($site->runtime === 'laravel' ? 'public' : '.');
        $this->hostingPhpVersion = $site->php_version;
        $this->hostingNodeVersion = $site->node_version ?? $this->hostingNodeVersion;
        $limits = $site->hosting_limits ?? [];
        $this->hostingUploadLimit = $limits['upload_limit'] ?? $this->hostingUploadLimit;
        $this->hostingMemoryLimit = $limits['memory_limit'] ?? $this->hostingMemoryLimit;
        $this->hostingExecutionTimeout = (string) ($limits['execution_timeout'] ?? $this->hostingExecutionTimeout);
        $this->environment = $site->environment ?? '';
        $this->deployCommands = $site->deploy_commands ?? '';
        $this->nginxConfig = $site->nginx_config ?? '';
        $this->healthUrl = $site->health_url ?? 'https://'.$site->domain;
    }

    public function inspect(): void
    {
        $this->queue('inspect');
    }

    public function saveHosting(): void
    {
        abort_unless(Auth::check(), 403);
        if ($this->site->fresh()->status !== 'active') {
            $this->addError('hosting', 'El dominio debe estar activo antes de cambiar Hosting.');

            return;
        }
        $data = $this->validate([
            'documentRoot' => ['required', 'string', 'max:255', 'regex:~^(?:\.|[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)$~'],
            'hostingPhpVersion' => ['required', Rule::in($this->phpVersions())],
            'hostingNodeVersion' => ['required', Rule::in(collect($this->nodeVersions())->pluck('version')->all())],
            'hostingUploadLimit' => ['required', Rule::in(['64M', '128M', '256M', '512M', '1g', '2g'])],
            'hostingMemoryLimit' => ['required', Rule::in(['128M', '256M', '512M', '768M', '1G'])],
            'hostingExecutionTimeout' => ['required', 'integer', Rule::in([30, 60, 120, 300, 600])],
        ]);
        $this->queue('configure-hosting', [$data['documentRoot'], $data['hostingPhpVersion'], $data['hostingUploadLimit'], $data['hostingMemoryLimit'], (string) $data['hostingExecutionTimeout'], (string) $data['hostingNodeVersion']]);
    }

    public function phpVersions(): array
    {
        $knownVersions = ['8.2', '8.3', '8.4'];
        if (config('minipanel.execution_enabled')) {
            $detectedVersions = Cache::remember('server-php-versions', now()->addMinutes(15), function (): array {
                try {
                    $result = Process::timeout(5)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-php-versions']);

                    return $result->successful()
                        ? collect(preg_split('/\R/', $result->output()))
                            ->map(fn (string $line): string => explode("\t", $line, 2)[0])
                            ->filter(fn (string $version): bool => preg_match('/^8\.[0-9]{1,2}$/', $version) === 1)
                            ->values()
                            ->all()
                        : [];
                } catch (\Throwable) {
                    return [];
                }
            });
            $knownVersions = $detectedVersions !== [] ? $detectedVersions : $knownVersions;
        }

        return collect([...$knownVersions, $this->site->php_version])
            ->filter(fn (string $version): bool => preg_match('/^8\.[0-9]{1,2}$/', $version) === 1)
            ->unique()
            ->sort(fn (string $left, string $right): int => version_compare($right, $left))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{version: string, state: string}>
     */
    public function nodeVersions(): array
    {
        $knownVersions = [
            ['version' => '20', 'state' => 'disponible'],
            ['version' => '22', 'state' => 'disponible'],
            ['version' => '24', 'state' => 'disponible'],
        ];
        if (config('minipanel.execution_enabled')) {
            $detectedVersions = Cache::remember('server-node-versions', now()->addMinutes(15), function (): array {
                try {
                    $result = Process::timeout(15)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-node-versions']);

                    return $result->successful()
                        ? collect(preg_split('/\R/', $result->output()))
                            ->map(function (string $line): ?array {
                                [$version, $state] = array_pad(explode("\t", $line, 2), 2, null);

                                return is_string($version) && preg_match('/^[1-9][0-9]$/', $version) === 1 && (int) $version >= 18 && in_array($state, ['instalado', 'disponible'], true)
                                    ? ['version' => $version, 'state' => $state]
                                    : null;
                            })
                            ->filter()
                            ->values()
                            ->all()
                        : [];
                } catch (\Throwable) {
                    return [];
                }
            });
            $knownVersions = $detectedVersions !== [] ? $detectedVersions : $knownVersions;
        }

        $configuredVersion = $this->site->node_version ?? $this->hostingNodeVersion;
        if (preg_match('/^[1-9][0-9]$/', $configuredVersion) === 1 && (int) $configuredVersion >= 18 && ! collect($knownVersions)->contains('version', $configuredVersion)) {
            $knownVersions[] = ['version' => $configuredVersion, 'state' => 'instalado'];
        }

        return collect($knownVersions)
            ->unique('version')
            ->sortByDesc(fn (array $node): int => (int) $node['version'])
            ->values()
            ->all();
    }

    public function issueCertificate(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless($this->site->fresh()->status === 'active', 422, 'El dominio debe estar activo.');
        $this->validate([
            'acceptCertificateTerms' => ['accepted'],
        ]);
        $email = Auth::user()->email;
        validator(['certificateEmail' => $email], ['certificateEmail' => ['required', 'email', 'max:254']])->validate();
        $this->queue('issue-ssl', [], ['certificate_email' => $email]);
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

    public function selectBackupForRestore(int $deploymentId): void
    {
        abort_unless(Auth::check(), 403);
        $this->site->deployments()
            ->whereKey($deploymentId)
            ->where('action', 'backup')
            ->where('status', 'finished')
            ->firstOrFail();
        $this->restoreBackupDeploymentId = $deploymentId;
        $this->restoreConfirmation = '';
    }

    public function restoreBackup(): void
    {
        $expected = 'RESTAURAR '.$this->site->domain;
        $this->validate(['restoreConfirmation' => ['required', 'in:'.$expected]]);
        $backup = $this->site->deployments()
            ->whereKey($this->restoreBackupDeploymentId)
            ->where('action', 'backup')
            ->where('status', 'finished')
            ->firstOrFail();
        abort_unless(preg_match('~^/var/backups/minipanel/'.preg_quote($this->site->resourceDomain(), '~').'-[0-9]{8}-[0-9]{6}\\.tar\\.gz$~', trim($backup->output)) === 1, 422, 'La ruta del respaldo no es válida.');
        $this->queue('restore-backup', [$this->restoreConfirmation, trim($backup->output)]);
        $this->reset('restoreConfirmation', 'restoreBackupDeploymentId');
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
        abort_unless(Auth::check(), 403);
        abort_unless($this->site->fresh()->status === 'active', 422);
        $this->queue('suspend-site');
    }

    public function resumeSite(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless($this->site->fresh()->status === 'suspended', 422);
        $this->queue('resume-site');
    }

    public function deleteSite(): void
    {
        abort_unless(Auth::check(), 403);
        throw ValidationException::withMessages(['dangerConfirmation' => 'Usa Eliminar en la cabecera de la lista de dominios para elegir papelera o borrado inmediato.']);
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
        abort_unless(Auth::check(), 403);
        abort_if($this->site->fresh()?->lifecycle_action || ! $this->site->fresh(), 422, 'El dominio está procesando una operación de ciclo de vida.');
        abort_if($this->site->deployments()->whereIn('action', ['configure-hosting', 'issue-ssl', 'suspend-site', 'resume-site'])->whereIn('status', ['queued', 'running'])->exists(), 422, 'Espera a que termine la operación de hosting en curso.');
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) max($bytes, 0);
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return rtrim(rtrim(number_format($value, $index === 0 ? 0 : 1, '.', ''), '0'), '.').' '.$units[$index];
    }

    public function render()
    {
        $this->site->refresh();
        $this->site->loadExists(['repositories as has_laravel_repository' => fn ($query) => $query->where('project_type', 'Laravel')]);
        $primaryRepository = $this->site->repositories()->where('status', 'ready')->latest('synced_at')->first();

        return view('livewire.site-manager', ['snapshot' => Cache::get('site-snapshot-'.$this->site->id, []), 'snapshotExists' => Storage::disk('local')->exists('site-snapshots/'.$this->site->id.'.jpg'), 'activity' => $this->site->deployments()->latest()->take(12)->get(), 'backups' => $this->site->deployments()->where('action', 'backup')->where('status', 'finished')->latest()->take(12)->get(), 'queues' => $this->site->queues()->orderBy('name')->get(), 'dnsRun' => $this->site->dnsChecks()->latest()->first(), 'serverIp' => ServerSetting::query()->value('public_ip'), 'serverTimezone' => ServerSetting::query()->value('timezone') ?: 'America/Mexico_City', 'primaryRepository' => $primaryRepository, 'currentCommit' => data_get($primaryRepository?->commits, '0.hash') ?? $this->site->current_commit])
            ->layout('components.layouts.app');
    }
}
