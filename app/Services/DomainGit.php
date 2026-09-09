<?php

namespace App\Services;

use App\Jobs\RunRepositorySync;
use App\Models\Site;
use App\Models\SiteRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DomainGit
{
    public function available(Site $site): void
    {
        $site = $site->fresh();
        if (! $site || $site->lifecycle_action || $site->status !== 'active') {
            throw ValidationException::withMessages(['git' => 'El dominio debe estar activo y sin operaciones pendientes.']);
        }
        if (! config('minipanel.execution_enabled')) {
            throw ValidationException::withMessages(['git' => 'La ejecución está desactivada en este equipo.']);
        }
    }

    public function draft(Site $site): SiteRepository
    {
        $this->available($site);

        return Cache::lock('git-draft-'.$site->id, 40)->block(3, function () use ($site) {
            $repository = $site->repositories()->firstOrCreate(['status' => 'draft'], ['key_token' => (string) Str::uuid()]);
            if (! $repository->public_key) {
                $result = $this->quick($site, 'key', ['id' => $repository->key_token]);
                $repository->update(['public_key' => $result['public_key']]);
            }

            return $repository;
        });
    }

    public function quick(Site $site, string $action, array $payload): array
    {
        $this->available($site);
        $result = Process::timeout(30)->input(json_encode($payload, JSON_THROW_ON_ERROR))->run($this->command($site, $action));
        if (! $result->successful()) {
            throw ValidationException::withMessages(['git' => trim($result->errorOutput()) ?: 'No se pudo completar la operación en el servidor.']);
        }

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function command(Site $site, string $action): array
    {
        return ['sudo', '/usr/local/bin/minipanel-agent', 'repository', $site->path, $site->resourceDomain(), '', 'main', $site->php_version, '0', '0', 'static', '', $action];
    }

    public function sync(SiteRepository $repository, ?string $branch = null): void
    {
        $this->available($repository->site);
        DB::transaction(function () use ($repository, $branch) {
            $site = Site::whereKey($repository->site_id)->lockForUpdate()->firstOrFail();
            $this->available($site);
            $repository = $site->repositories()->findOrFail($repository->id);
            if ($site->repositories()->whereIn('status', ['queued', 'running'])->exists() || $site->deployments()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['git' => 'Espera a que termine la operación actual del dominio.']);
            }
            if (! $repository->url || ! $repository->directory) {
                throw ValidationException::withMessages(['git' => 'Completa la configuración del repositorio.']);
            }
            if ($branch !== null && ! in_array($branch, $repository->branches ?? [], true)) {
                throw ValidationException::withMessages(['git' => 'Selecciona una rama disponible.']);
            }
            $token = (string) Str::uuid();
            $repository->update(['status' => 'queued', 'operation_token' => $token, 'error' => null, 'steps' => []]);
            RunRepositorySync::dispatch($repository->id, $token, $branch ?? $repository->branch)->afterCommit();
            app(AuditLogger::class)->record('git.sync-requested', $site);
        });
    }
}
