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
        abort_unless($this->site->fresh()?->status === 'active', 422, 'El dominio debe estar activo.');
        $this->validate(['acceptCertificateTerms' => ['accepted']]);
        abort_if($this->site->deployments()->where('action', 'issue-ssl')->whereIn('status', ['queued', 'running'])->exists(), 422, 'Ya hay una operación SSL en curso.');

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
