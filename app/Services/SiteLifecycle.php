<?php

namespace App\Services;

use App\Jobs\RunSiteLifecycle;
use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteLifecycle
{
    public function request(int $id, string $action, ?string $newDomain = null): void
    {
        if (! config('minipanel.execution_enabled')) {
            throw ValidationException::withMessages(['siteAction' => 'La ejecución está desactivada en este equipo.']);
        }
        if (! in_array($action, ['rename', 'trash', 'purge', 'restore'], true)) {
            throw ValidationException::withMessages(['siteAction' => 'Operación no válida.']);
        }
        Cache::lock('site-lifecycle-requests', 15)->block(3, function () use ($id, $action, $newDomain): void {
            DB::transaction(function () use ($id, $action, $newDomain): void {
                $site = Site::withTrashed()->findOrFail($id);
                if ($action === 'rename') {
                    $this->validateRename($site, $newDomain);
                }
                if ($action === 'restore' && (! $site->trashed() || $site->purge_after?->isPast())) {
                    throw ValidationException::withMessages(['siteAction' => 'El plazo para restaurar terminó o el dominio no está en la papelera.']);
                }
                $sites = collect([$site]);
                if ($action !== 'rename') {
                    $sites = $site->trashed() && $site->trash_group
                        ? Site::withTrashed()->where('trash_group', $site->trash_group)->get()
                        : Site::withTrashed()->where(fn ($query) => $query->whereKey($site->id)->orWhere('parent_site_id', $site->id))->get();
                }
                foreach ($sites as $member) {
                    if ($action === 'restore' && (! $member->trashed() || $member->purge_after?->isPast())) {
                        throw ValidationException::withMessages(['siteAction' => 'No se puede restaurar el grupo: el plazo terminó para '.$member->domain.'.']);
                    }
                    if ($member->lifecycle_action || $member->lifecycle_error ||
                        $member->repositories()->whereIn('status', ['queued', 'running'])->exists() ||
                        $member->deployments()->where('status', 'running')->exists() ||
                        $member->fileOperations()->whereIn('status', ['pending', 'queued', 'running'])->exists() ||
                        SiteDatabaseOperation::where('site_id', $member->id)->whereIn('status', ['pending', 'queued', 'running'])->exists()) {
                        throw ValidationException::withMessages(['siteAction' => 'Hay operaciones pendientes o una operación incompleta en '.$member->domain.'. Resuélvela antes de continuar.']);
                    }
                }
                $group = $site->trash_group ?? (string) Str::uuid();
                foreach ($sites as $member) {
                    $member->deployments()->where('status', 'queued')->update(['status' => 'cancelled', 'output' => 'Cancelada por una operación de ciclo de vida del dominio.', 'finished_at' => now()]);
                    $member->update([
                        'server_domain' => $member->resourceDomain(), 'lifecycle_action' => $action,
                        'lifecycle_token' => bin2hex(random_bytes(16)),
                        'lifecycle_previous_status' => $member->lifecycle_previous_status ?? $member->status,
                        'pending_domain' => $action === 'rename' ? $newDomain : null,
                        'trash_group' => $action === 'rename' ? null : $group,
                    ]);
                }
                $ids = $sites->sortBy(fn (Site $member) => $member->parent_site_id === null ? 0 : 1)->pluck('id')->all();
                if ($action !== 'restore') {
                    $ids = array_reverse($ids);
                }
                RunSiteLifecycle::dispatch($ids, $action)->afterCommit();
                app(AuditLogger::class)->record('site.'.$action.'.requested', $site, ['site_ids' => $ids, 'new_domain' => $newDomain]);
            });
        });
    }

    private function validateRename(Site $site, ?string $domain): void
    {
        Validator::make(['newDomain' => $domain], ['newDomain' => ['required', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/']])->validate();
        if ($site->trashed() || ! in_array($site->status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['siteAction' => 'Sólo se puede renombrar un dominio preparado.']);
        }
        if ($domain === $site->domain || Site::withTrashed()->where(fn ($query) => $query->where('domain', $domain)->orWhere('pending_domain', $domain)->orWhere('server_domain', $domain))->exists() ||
            Site::withTrashed()->get()->contains(fn (Site $other) => in_array($domain, $other->aliases ?? [], true))) {
            throw ValidationException::withMessages(['newDomain' => 'Ese nombre está en uso o reservado, incluso en la papelera.']);
        }
    }
}
