<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class BackupManager extends Component
{
    public Site $site;

    public ?int $restoreBackupDeploymentId = null;

    public string $restoreConfirmation = '';

    public bool $backupEnabled = true;

    public int $backupRetention = 3;

    public function mount(Site $site): void
    {
        abort_unless(Auth::check(), 403);
        $this->site = $site;
        $this->backupEnabled = $site->backup_enabled;
        $this->backupRetention = $site->backup_retention;
    }

    public function createBackup(): void
    {
        $this->queue('backup');
    }

    public function saveBackupPolicy(): void
    {
        abort_unless(Auth::check(), 403);
        $validated = $this->validate([
            'backupEnabled' => ['boolean'],
            'backupRetention' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $this->site->update([
            'backup_enabled' => $validated['backupEnabled'],
            'backup_retention' => $validated['backupRetention'],
        ]);

        session()->flash('notice', 'Política de respaldos actualizada.');
    }

    public function selectBackupForRestore(int $deploymentId): void
    {
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
        abort_unless(Auth::check(), 403);
        $expected = 'RESTAURAR '.$this->site->domain;
        $this->validate(['restoreConfirmation' => ['required', 'in:'.$expected]]);
        $backup = $this->site->deployments()
            ->whereKey($this->restoreBackupDeploymentId)
            ->where('action', 'backup')
            ->where('status', 'finished')
            ->firstOrFail();
        $backupPath = $this->backupPath($backup);
        $this->queue('restore-backup', [$this->restoreConfirmation, $backupPath]);
        $this->reset('restoreConfirmation', 'restoreBackupDeploymentId');
    }

    public function verifyBackup(int $deploymentId): void
    {
        abort_unless(Auth::check(), 403);
        $backup = $this->site->deployments()
            ->whereKey($deploymentId)
            ->where('action', 'backup')
            ->where('status', 'finished')
            ->firstOrFail();

        $this->queue('verify-backup', [$this->backupPath($backup)]);
    }

    public function render()
    {
        return view('livewire.backup-manager', [
            'backups' => $this->site->deployments()->where('action', 'backup')->where('status', 'finished')->latest()->take(30)->get(),
            'activity' => $this->site->deployments()->whereIn('action', ['backup', 'verify-backup', 'prune-backups', 'restore-backup'])->latest()->take(12)->get(),
        ])->layout('components.layouts.app');
    }

    private function queue(string $action, array $arguments = []): void
    {
        abort_unless(Auth::check() && $this->site->fresh()?->status === 'active' && ! $this->site->fresh()?->lifecycle_action, 403);
        $deployment = Deployment::create([
            'site_id' => $this->site->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'parameters' => ['arguments' => $arguments],
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);
        RunDeployment::dispatch($deployment->id);
        app(AuditLogger::class)->record('operation.queued', $this->site, ['action' => $action, 'deployment_id' => $deployment->id]);
        session()->flash('notice', match ($action) {
            'backup' => 'Respaldo puesto en cola.',
            'verify-backup' => 'Verificación del respaldo puesta en cola.',
            default => 'Restauración puesta en cola.',
        });
    }

    private function backupPath(Deployment $backup): string
    {
        $backupPath = trim($backup->output);
        abort_unless(preg_match('~^/var/backups/minipanel/'.preg_quote($this->site->resourceDomain(), '~').'-[0-9]{8}-[0-9]{6}\\.tar\\.gz$~', $backupPath) === 1, 422, 'La ruta del respaldo no es válida.');

        return $backupPath;
    }
}
