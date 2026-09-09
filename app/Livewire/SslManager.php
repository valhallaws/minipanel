<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DnsSetting;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SslManager extends Component
{
    public Site $site;

    #[Locked]
    public bool $embedded = false;

    public bool $acceptCertificateTerms = false;

    public function mount(Site $site, bool $embedded = false): void
    {
        abort_unless(Auth::check(), 403);
        $this->site = $site;
        $this->embedded = $embedded;
    }

    public function issueCertificate(): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetErrorBag();
        if ($this->site->fresh()?->status !== 'active') {
            $this->addError('certificate', 'El dominio aún se está aprovisionando. Espera a que quede activo antes de solicitar el certificado.');

            return;
        }
        $this->validate(['acceptCertificateTerms' => ['accepted']]);
        if ($this->site->deployments()->where('action', 'issue-ssl')->whereIn('status', ['queued', 'running'])->exists()) {
            $this->addError('certificate', 'Ya hay una operación SSL en curso.');

            return;
        }

        $deployment = Deployment::create([
            'site_id' => $this->site->id,
            'user_id' => Auth::id(),
            'action' => 'issue-ssl',
            'parameters' => ['arguments' => [], 'certificate_email' => Auth::user()->email],
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);
        RunDeployment::dispatch($deployment->id);
        app(AuditLogger::class)->record('operation.queued', $this->site, ['action' => 'issue-ssl', 'deployment_id' => $deployment->id]);
        $this->acceptCertificateTerms = false;
        session()->flash('notice', 'Solicitud de certificado puesta en cola.');
    }

    public function render()
    {
        $this->site->refresh();

        $view = view('livewire.ssl-manager', [
            'dnsConfiguration' => DnsSetting::find(1),
            'latestOperation' => $this->site->deployments()->where('action', 'issue-ssl')->latest()->first(),
        ]);

        if ($this->embedded) {
            return $view;
        }

        return $view->layout('components.layouts.app');
    }
}
