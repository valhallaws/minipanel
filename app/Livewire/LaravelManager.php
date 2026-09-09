<?php

namespace App\Livewire;

use App\Jobs\RunLaravelCommand;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\DomainLaravel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LaravelManager extends Component
{
    public Site $site;

    #[Locked]
    public bool $embedded = false;

    public int $repositoryId = 0;

    public string $tool = 'artisan';

    public string $command = 'list';

    #[Locked]
    public array $artisanSuggestions = [];

    public bool $showEnvironment = false;

    public string $environment = '';

    #[Locked]
    public string $environmentHash = '';

    #[Locked]
    public ?int $environmentRepositoryId = null;

    public string $maintenanceSecret = '';

    public ?bool $maintenance = null;

    public string $queueName = 'default';

    public int $workers = 1;

    public int $tries = 3;

    public int $workerTimeout = 60;

    public function mount(Site $site, bool $embedded = false): void
    {
        abort_unless(Auth::check(), 403);
        $this->site = $site;
        $this->embedded = $embedded;
        $this->repositoryId = (int) ($site->repositories()->where('project_type', 'Laravel')->orderBy('id')->value('id') ?? 0);
        app(DomainLaravel::class)->directory($site, $this->repositoryId);
    }

    public function updatedRepositoryId(): void
    {
        $this->closeEnvironment();
        $this->maintenance = null;
        $this->maintenanceSecret = '';
        $this->artisanSuggestions = [];
        app(DomainLaravel::class)->directory($this->site, $this->repositoryId);
        $this->inspectLaravel();
    }

    public function inspectLaravel(): void
    {
        $state = app(DomainLaravel::class)->quick($this->site, $this->repositoryId, 'laravel-state');
        $this->maintenance = $state['maintenance'];
        $this->artisanSuggestions = $state['commands'] ?? [];
    }

    public function openEnvironment(): void
    {
        $result = app(DomainLaravel::class)->quick($this->site, $this->repositoryId, 'laravel-env-read');
        $this->environment = $result['contents'];
        $this->environmentHash = $result['hash'];
        $this->environmentRepositoryId = $this->repositoryId;
        $this->showEnvironment = true;
    }

    public function closeEnvironment(): void
    {
        $this->reset('environment', 'environmentHash', 'environmentRepositoryId', 'showEnvironment');
    }

    public function saveEnvironment(): void
    {
        abort_unless($this->showEnvironment && $this->environmentHash !== '' && $this->environmentRepositoryId === $this->repositoryId, 422);
        $this->validate(['environment' => ['nullable', 'string', 'max:1048576']]);
        $result = app(DomainLaravel::class)->quick($this->site, $this->repositoryId, 'laravel-env-write', ['contents' => $this->environment, 'hash' => $this->environmentHash]);
        $this->closeEnvironment();
        session()->flash('notice', ($result['caches_rebuilt'] ?? false)
            ? '.env guardado con permisos privados. Las cachés de Laravel se regeneraron.'
            : '.env guardado con permisos privados. La caché se regenerará cuando el proyecto tenga sus dependencias instaladas.');
    }

    public function setMaintenance(bool $enabled): void
    {
        if ($enabled) {
            $this->validate(['maintenanceSecret' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,128}$/']]);
        }
        $result = app(DomainLaravel::class)->quick($this->site, $this->repositoryId, 'laravel-maintenance', ['enabled' => $enabled, 'secret' => $enabled ? $this->maintenanceSecret : '']);
        $this->maintenance = $result['maintenance'];
        $this->maintenanceSecret = '';
        session()->flash('notice', $enabled ? 'Modo mantenimiento activado.' : 'Proyecto fuera de mantenimiento.');
    }

    public function runCommand(): void
    {
        $this->validate(['tool' => ['required', 'in:artisan,composer,npm'], 'command' => ['required', 'string', 'max:2000', 'regex:~^[A-Za-z0-9_.,:@=+/-]+(?:\s+[A-Za-z0-9_.,:@=+/-]+)*$~']]);
        $this->enqueue(['tool' => $this->tool, 'arguments' => preg_split('/\s+/', trim($this->command))]);
    }

    public function scheduleList(): void
    {
        $this->enqueue(['tool' => 'artisan', 'arguments' => ['schedule:list']]);
    }

    public function queueCommand(string $command): void
    {
        abort_unless(in_array($command, ['queue:restart', 'queue:failed'], true), 422);
        $this->enqueue(['tool' => 'artisan', 'arguments' => [$command]]);
    }

    public function scheduler(bool $enabled): void
    {
        $this->enqueue(['service' => 'schedule', 'enabled' => $enabled]);
    }

    public function queueService(bool $enabled): void
    {
        $this->validate(['queueName' => ['required', 'regex:/^[A-Za-z0-9_-]{1,32}$/'], 'workers' => ['integer', 'between:1,12'], 'tries' => ['integer', 'between:1,20'], 'workerTimeout' => ['integer', 'between:10,3600']]);
        $this->enqueue(['service' => 'queue', 'enabled' => $enabled, 'name' => $this->queueName, 'workers' => $this->workers, 'tries' => $this->tries, 'timeout' => $this->workerTimeout]);
    }

    public function serviceStatus(): void
    {
        $this->enqueue(['service' => 'status', 'enabled' => false]);
    }

    public function installPuppeteer(): void
    {
        $this->enqueue(['task' => 'puppeteer']);
    }

    private function enqueue(array $parameters): void
    {
        app(DomainLaravel::class)->directory($this->site, $this->repositoryId);
        DB::transaction(function () use ($parameters): void {
            $site = Site::whereKey($this->site->id)->lockForUpdate()->firstOrFail();
            abort_if($site->deployments()->whereIn('status', ['queued', 'running'])->exists() || $site->repositories()->whereIn('status', ['queued', 'running'])->exists(), 422, 'Espera a que termine la operación actual.');
            $operation = Deployment::create(['site_id' => $site->id, 'user_id' => Auth::id(), 'action' => 'laravel-command', 'parameters' => ['repository_id' => $this->repositoryId, ...$parameters], 'status' => 'queued', 'output' => 'Pendiente de ejecución…']);
            RunLaravelCommand::dispatch($operation->id)->afterCommit();
        });
    }

    public function render()
    {
        $activity = $this->site->deployments()
            ->where('action', 'laravel-command')
            ->where('parameters->repository_id', $this->repositoryId)
            ->latest('id')
            ->take(10)
            ->get();
        $scheduleInspection = $activity->first(fn (Deployment $operation): bool => ($operation->parameters['arguments'][0] ?? null) === 'schedule:list' && $operation->status === 'finished');

        return view('livewire.laravel-manager', [
            'repositories' => $this->site->repositories()->where('project_type', 'Laravel')->orderBy('id')->get(),
            'directory' => app(DomainLaravel::class)->directory($this->site, $this->repositoryId),
            'activity' => $activity,
            'scheduleInspection' => $scheduleInspection,
            'scheduledTasks' => $this->scheduledTasks($scheduleInspection?->output ?? ''),
        ])->layout('components.layouts.app');
    }

    /**
     * @return array<int, array{expression: string, cadence: string, command: string, next: string}>
     */
    private function scheduledTasks(string $output): array
    {
        return collect(preg_split('/\R/', $output))
            ->map(fn (string $line): ?array => $this->scheduledTaskFromLine($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{expression: string, cadence: string, command: string, next: string}|null
     */
    private function scheduledTaskFromLine(string $line): ?array
    {
        if (! preg_match('/^\s*(?<expression>\S+(?:\s+\S+){4})\s+(?<command>.+?)\s+(?:\.{2,}\s+)?Next Due:\s*(?<next>.+)$/', $line, $matches)) {
            return null;
        }

        $expression = preg_replace('/\s+/', ' ', trim($matches['expression']));
        $command = rtrim(trim($matches['command']), '. ');

        return [
            'expression' => $expression,
            'cadence' => $this->scheduleCadence($expression),
            'command' => $command,
            'next' => trim($matches['next']),
        ];
    }

    private function scheduleCadence(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Cada minuto',
            '0 * * * *' => 'Cada hora',
            '0 0 * * *' => 'Cada día a las 00:00',
            '0 0 * * 0' => 'Cada domingo a las 00:00',
            default => preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $matches)
                ? 'Cada '.$matches[1].' minuto'.($matches[1] === '1' ? '' : 's')
                : 'Programación personalizada',
        };
    }
}
