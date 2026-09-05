<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Dashboard extends Component
{
    public bool $showCreate = false;

    public string $name = '';

    public string $domain = '';

    public string $repository = '';

    public string $repositoryProtocol = 'https';

    public string $branch = 'main';

    public string $phpVersion = '8.3';

    public string $runtime = 'laravel';

    public array $phpExtensions = ['bcmath', 'curl', 'mbstring', 'mysql', 'xml', 'zip', 'gd', 'intl'];

    public bool $queueEnabled = false;

    public function createSite(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'domain' => ['required', 'lowercase', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::unique('sites')],
            'repository' => ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/\S+|git@[A-Za-z0-9.-]+:[A-Za-z0-9._\/-]+\.git|ssh:\/\/\S+)$/'],
            'branch' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'phpVersion' => ['required', Rule::in(['8.2', '8.3', '8.4'])],
            'runtime' => ['required', Rule::in(['laravel', 'static'])],
            'phpExtensions' => ['array'],
            'phpExtensions.*' => [Rule::in(['bcmath', 'curl', 'mbstring', 'mysql', 'xml', 'zip', 'gd', 'intl', 'redis', 'soap', 'imagick'])],
            'queueEnabled' => ['boolean'],
        ]);

        $site = Site::create([
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'repository' => $validated['repository'] ?: null,
            'branch' => $validated['branch'],
            'path' => '/var/www/'.$validated['domain'],
            'php_version' => $validated['phpVersion'],
            'runtime' => $validated['runtime'],
            'php_extensions' => $validated['runtime'] === 'laravel' ? $validated['phpExtensions'] : [],
            'queue_enabled' => $validated['queueEnabled'],
        ]);

        $this->queueAction($site, 'provision');
        $this->reset('name', 'domain', 'repository', 'repositoryProtocol', 'branch', 'phpVersion', 'runtime', 'phpExtensions', 'queueEnabled');
        $this->branch = 'main';
        $this->phpVersion = '8.3';
        $this->runtime = 'laravel';
        $this->repositoryProtocol = 'https';
        $this->phpExtensions = ['bcmath', 'curl', 'mbstring', 'mysql', 'xml', 'zip', 'gd', 'intl'];
        $this->showCreate = false;
        session()->flash('notice', 'Sitio creado. La provisión fue puesta en cola.');
    }

    public function deploy(int $siteId): void
    {
        $this->queueAction(Site::findOrFail($siteId), 'deploy');
        session()->flash('notice', 'Despliegue puesto en cola.');
    }

    public function issueCertificate(int $siteId): void
    {
        $this->queueAction(Site::findOrFail($siteId), 'issue-ssl');
        session()->flash('notice', 'Solicitud de certificado puesta en cola.');
    }

    private function queueAction(Site $site, string $action): void
    {
        $deployment = Deployment::create([
            'site_id' => $site->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);

        RunDeployment::dispatch($deployment->id);
        app(AuditLogger::class)->record('operation.queued', $site, ['action' => $action, 'deployment_id' => $deployment->id]);
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'sites' => Site::latest()->get(),
            'activity' => Deployment::with('site')->latest()->take(8)->get(),
        ])->layout('components.layouts.app');
    }
}
