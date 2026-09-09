<?php

namespace App\Livewire;

use App\Jobs\RunDeployment;
use App\Jobs\RunSiteLifecycle;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\SiteLifecycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Dashboard extends Component
{
    #[Locked]
    public ?int $actionSiteId = null;

    #[Locked]
    public string $siteActionMode = '';

    public string $newDomain = '';

    public bool $deleteImmediately = false;

    public bool $showTrash = false;

    public function openSiteAction(int $id, string $action): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($action, ['rename', 'delete', 'restore', 'purge'], true), 422);
        $site = Site::withTrashed()->findOrFail($id);
        $this->resetValidation();
        $this->actionSiteId = $id;
        $this->siteActionMode = $action;
        $this->newDomain = $site->domain;
        $this->deleteImmediately = false;
    }

    public function closeSiteAction(): void
    {
        $this->reset('actionSiteId', 'siteActionMode', 'newDomain', 'deleteImmediately');
        $this->resetValidation();
    }

    public function confirmSiteAction(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless($this->actionSiteId !== null && in_array($this->siteActionMode, ['rename', 'delete', 'restore', 'purge'], true), 422);
        $this->newDomain = strtolower(trim($this->newDomain));
        $action = $this->siteActionMode === 'delete' ? ($this->deleteImmediately ? 'purge' : 'trash') : $this->siteActionMode;
        app(SiteLifecycle::class)->request($this->actionSiteId, $action, $action === 'rename' ? $this->newDomain : null);
        $this->closeSiteAction();
        session()->flash('notice', 'Operación solicitada. El estado se actualizará al terminar; no cierres los avisos de error si aparecen.');
    }

    public function retrySiteAction(int $id): void
    {
        abort_unless(Auth::check(), 403);
        $site = Site::withTrashed()->findOrFail($id);
        abort_unless($site->lifecycle_error && $site->lifecycle_action, 422);
        $sites = $site->trash_group
            ? Site::withTrashed()->where('trash_group', $site->trash_group)->where('lifecycle_action', $site->lifecycle_action)->get()
            : collect([$site]);
        $ids = $sites->sortBy(fn (Site $member) => $member->parent_site_id === null ? 0 : 1)->pluck('id')->all();
        if ($site->lifecycle_action !== 'restore') {
            $ids = array_reverse($ids);
        }
        Site::withTrashed()->whereIn('id', $ids)->update(['lifecycle_error' => null]);
        RunSiteLifecycle::dispatch($ids, $site->lifecycle_action);
    }

    public bool $showCreate = false;

    public string $name = '';

    public string $domain = '';

    public ?int $parentSiteId = null;

    public function changeSiteStatus(int $siteId, string $status): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetValidation();
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['siteAction' => 'Estado no válido.']);
        }
        Cache::lock('site-status-'.$siteId, 10)->block(2, function () use ($siteId, $status): void {
            $site = Site::findOrFail($siteId);
            if ($site->lifecycle_action || ! in_array($site->status, ['active', 'suspended'], true)) {
                throw ValidationException::withMessages(['siteAction' => 'Completa primero la preparación del dominio.']);
            }
            if ($site->deployments()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['siteAction' => 'Espera a que termine la operación del dominio.']);
            }
            if ($site->status !== $status) {
                $this->queueAction($site, $status === 'active' ? 'resume-site' : 'suspend-site');
            }
        });
        session()->flash('notice', 'Cambio de estado solicitado. Se actualizará al terminar la operación.');
    }

    public function createSite(): void
    {
        abort_unless(Auth::check(), 403);
        Cache::lock('site-lifecycle-requests', 15)->block(3, fn () => $this->createSiteUnderLock());
    }

    private function createSiteUnderLock(): void
    {
        abort_unless(Auth::check(), 403);

        $this->domain = strtolower(trim($this->domain));
        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'domain' => ['required', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,}$/', Rule::unique('sites'), Rule::unique('sites', 'server_domain'), Rule::unique('sites', 'pending_domain')],
            'parentSiteId' => ['nullable', 'integer', Rule::exists('sites', 'id')->whereNull('parent_site_id')],
        ]);

        if ($validated['parentSiteId'] !== null) {
            $parent = Site::findOrFail($validated['parentSiteId']);
            if (! str_ends_with($validated['domain'], '.'.$parent->domain)) {
                throw ValidationException::withMessages(['domain' => 'El subdominio debe pertenecer al dominio padre seleccionado.']);
            }
            if ($parent->lifecycle_action || $parent->trashed() || $parent->status !== 'active') {
                throw ValidationException::withMessages(['parentSiteId' => 'El dominio padre debe estar activo.']);
            }
        }

        DB::transaction(function () use ($validated): void {
            $site = Site::create([
                'name' => $validated['name'] ?: $validated['domain'],
                'domain' => $validated['domain'],
                'parent_site_id' => $validated['parentSiteId'],
                'path' => '/var/www/'.$validated['domain'],
                'repository' => null,
                'document_root' => 'httpdocs',
                'branch' => 'main',
                'php_version' => '8.3',
                'runtime' => 'static',
                'php_extensions' => [],
                'queue_enabled' => false,
                'scheduler_enabled' => false,
            ]);

            $this->queueAction($site, 'provision');
        });

        $this->reset('name', 'domain', 'parentSiteId', 'showCreate');
        session()->flash('notice', 'Dominio registrado. Preparando su carpeta y alojamiento; no se desplegará ninguna aplicación.');
    }

    public function deploy(int $siteId): void
    {
        $this->queueAction(Site::findOrFail($siteId), 'deploy');
        session()->flash('notice', 'Despliegue puesto en cola.');
    }

    public function provision(int $siteId): void
    {
        $this->queueAction(Site::findOrFail($siteId), 'provision');
        session()->flash('notice', 'Aprovisionamiento puesto en cola.');
    }

    public function issueCertificate(int $siteId): void
    {
        $this->queueAction(Site::findOrFail($siteId), 'issue-ssl');
        session()->flash('notice', 'Solicitud de certificado puesta en cola.');
    }

    private function queueAction(Site $site, string $action): void
    {
        abort_unless(Auth::check(), 403);
        if ($site->fresh()?->lifecycle_action || $site->trashed()) {
            throw ValidationException::withMessages(['siteAction' => 'El dominio tiene una operación de ciclo de vida en curso.']);
        }
        $deployment = Deployment::create([
            'site_id' => $site->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);

        RunDeployment::dispatch($deployment->id)->afterCommit();
        app(AuditLogger::class)->record('operation.queued', $site, ['action' => $action, 'deployment_id' => $deployment->id]);
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'trash' => Site::onlyTrashed()->orderBy('purge_after')->get(),
            'lifecycleSites' => Site::withTrashed()->whereNotNull('lifecycle_action')->get(),
            'actionSite' => $this->actionSiteId ? Site::withTrashed()->with(['children' => fn ($query) => $query->withTrashed()])->find($this->actionSiteId) : null,
            'sites' => Site::orderBy('domain')->get(),
            'domainTree' => Site::whereNull('parent_site_id')->with('children')->orderBy('domain')->get(),
            'activity' => Deployment::with('site')->latest()->take(8)->get(),
        ])->layout('components.layouts.app');
    }
}
