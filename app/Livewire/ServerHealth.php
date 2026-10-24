<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\OperationsOverview;
use App\Services\ServerReadiness;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ServerHealth extends Component
{
    public array $checks = [];

    public string $checkedAt = '';

    public array $operations = [];

    /** @var list<string> */
    private const RETRYABLE_ACTIONS = ['provision', 'deploy', 'backup', 'verify-backup', 'health', 'dns-check', 'inspect'];

    public function mount(ServerReadiness $readiness, OperationsOverview $operations): void
    {
        $this->refresh($readiness, $operations);
    }

    public function refresh(ServerReadiness $readiness, OperationsOverview $operations): void
    {
        $this->checks = $readiness->inspect();
        $this->operations = $operations->inspect();
        $this->checkedAt = now()->format('H:i:s');
    }

    public function retryOperation(int $deploymentId): void
    {
        abort_unless(Auth::check(), 403);
        $operation = Deployment::with('site')->findOrFail($deploymentId);
        abort_unless(in_array($operation->status, ['failed', 'blocked'], true), 422);
        abort_unless(in_array($operation->action, self::RETRYABLE_ACTIONS, true), 422);
        $site = $operation->site;
        abort_unless($site && ! $site->trashed() && ! $site->lifecycle_action, 422);

        $this->queueAction($site, $operation->action, $operation->parameters ?? [], 'Reintento de '.$this->actionLabel($operation->action).' puesto en cola.');
    }

    public function checkSiteNow(int $siteId): void
    {
        abort_unless(Auth::check(), 403);
        $this->queueAction(Site::findOrFail($siteId), 'health', [], 'Comprobación de disponibilidad puesta en cola.');
    }

    public function createBackupNow(int $siteId): void
    {
        abort_unless(Auth::check(), 403);
        $this->queueAction(Site::findOrFail($siteId), 'backup', [], 'Respaldo puesto en cola.');
    }

    private function queueAction(Site $site, string $action, array $parameters, string $notice): void
    {
        abort_unless($site->status === 'active' || $action === 'provision', 422);
        abort_unless(! $site->lifecycle_action && ! $site->deployments()->whereIn('status', ['queued', 'running'])->exists(), 422);

        $deployment = Deployment::create([
            'site_id' => $site->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'parameters' => $parameters,
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);
        RunDeployment::dispatch($deployment->id);
        app(AuditLogger::class)->record('operation.recovery-queued', $site, ['action' => $action, 'deployment_id' => $deployment->id]);
        session()->flash('operationsNotice', $notice);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'health' => 'la comprobación',
            'dns-check' => 'la comprobación DNS',
            'inspect' => 'la inspección',
            default => str($action)->replace('-', ' ')->toString(),
        };
    }

    public function render()
    {
        return view('livewire.server-health')->layout('components.layouts.app');
    }
}
