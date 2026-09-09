<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunSiteLifecycle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param list<int> $siteIds */
    public function __construct(public array $siteIds, public string $action) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! config('minipanel.execution_enabled')) {
            $this->failed(new \RuntimeException('Ejecución desactivada.'));

            return;
        }
        foreach ($this->siteIds as $id) {
            $site = Site::withTrashed()->find($id);
            if (! $site || $site->lifecycle_action !== $this->action) {
                continue;
            }
            try {
                $result = Process::timeout(100)->input(json_encode(['new_domain' => $site->pending_domain, 'operation_token' => $site->lifecycle_token], JSON_THROW_ON_ERROR))->run([
                    'sudo', '/usr/local/bin/minipanel-agent', 'site-lifecycle', $site->path, $site->resourceDomain(),
                    '', 'main', $site->php_version, '0', '0', 'static', '', $this->action,
                ]);
                if (! $result->successful()) {
                    throw new \RuntimeException(trim($result->errorOutput()) ?: 'El agente no completó la operación.');
                }
                $receipt = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
                if (($receipt['action'] ?? null) !== $this->action || ($receipt['domain'] ?? null) !== $site->resourceDomain()) {
                    throw new \RuntimeException('Respuesta del agente no válida.');
                }
                DB::transaction(function () use ($site, $receipt): void {
                    app(AuditLogger::class)->record('site.'.$this->action.'.completed', $site);
                    if ($this->action === 'rename') {
                        $site->children()->withTrashed()->update(['parent_site_id' => null]);
                        $site->update(['domain' => $site->pending_domain, 'name' => $site->name === $site->domain ? $site->pending_domain : $site->name,
                            'parent_site_id' => null, 'pending_domain' => null, 'ssl_enabled' => false, 'ssl_auto_renew' => false,
                            'health_url' => null, 'lifecycle_action' => null, 'lifecycle_previous_status' => null]);
                    } elseif ($this->action === 'trash') {
                        $site->update(['purge_after' => Carbon::createFromTimestamp($receipt['purge_after']), 'lifecycle_action' => null]);
                        $site->delete();
                    } elseif ($this->action === 'restore') {
                        $site->restore();
                        $site->update(['status' => $site->lifecycle_previous_status, 'purge_after' => null, 'trash_group' => null, 'lifecycle_action' => null, 'lifecycle_previous_status' => null]);
                    } else {
                        SiteDatabaseOperation::where('site_id', $site->id)->delete();
                        $site->databaseUsers()->delete();
                        $site->databases()->delete();
                        $site->forceDelete();
                    }
                });
                Storage::disk('local')->delete('site-snapshots/'.$id.'.jpg');
                Cache::forget('site-snapshot-'.$id);
            } catch (Throwable $exception) {
                $this->failed($exception);

                return;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Site::withTrashed()->whereIn('id', $this->siteIds)->where('lifecycle_action', $this->action)->update([
            'lifecycle_error' => mb_substr($exception?->getMessage() ?? 'Operación interrumpida. Requiere revisión.', 0, 1000),
        ]);
    }
}
