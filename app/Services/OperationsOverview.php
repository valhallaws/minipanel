<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Support\Collection;

class OperationsOverview
{
    /**
     * @return array{
     *     active: Collection<int, Deployment>,
     *     stalled: Collection<int, Deployment>,
     *     failed: Collection<int, Deployment>,
     *     lifecycle: Collection<int, Site>,
     *     backups: Collection<int, Site>,
     *     availability: Collection<int, Site>
     * }
     */
    public function inspect(): array
    {
        return [
            'active' => Deployment::query()->with('site')->whereIn('status', ['queued', 'running'])->latest('created_at')->take(12)->get(),
            'stalled' => Deployment::query()
                ->with('site')
                ->where('status', 'running')
                ->where(function ($query): void {
                    $query->where('started_at', '<', now()->subMinutes(30))
                        ->orWhere(function ($query): void {
                            $query->whereNull('started_at')->where('created_at', '<', now()->subMinutes(30));
                        });
                })
                ->latest('started_at')
                ->take(12)
                ->get(),
            'failed' => Deployment::query()
                ->with('site')
                ->whereIn('status', ['failed', 'blocked'])
                ->where(function ($query): void {
                    $query->where('finished_at', '>=', now()->subDay())
                        ->orWhere(function ($query): void {
                            $query->whereNull('finished_at')->where('created_at', '>=', now()->subDay());
                        });
                })
                ->latest('finished_at')
                ->take(12)
                ->get(),
            'lifecycle' => Site::withTrashed()->whereNotNull('lifecycle_error')->whereNotNull('lifecycle_action')->orderByDesc('updated_at')->take(12)->get(),
            'backups' => Site::query()->where('status', 'active')->where('backup_enabled', true)->where(function ($query): void {
                $query->whereNull('last_backup_at')->orWhere('last_backup_at', '<', now()->subDays(7));
            })->orderBy('last_backup_at')->take(12)->get(),
            'availability' => Site::query()->where('status', 'active')->where(function ($query): void {
                $query->whereIn('health_status', ['failed', 'degraded'])
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('health_checked_at')->where('health_checked_at', '<', now()->subMinutes(30));
                    });
            })->orderBy('health_checked_at')->take(12)->get(),
        ];
    }
}
