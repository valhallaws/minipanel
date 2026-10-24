<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteHealthCheck;
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
     *     availability: Collection<int, Site>,
     *     certificates: Collection<int, Site>
     * }
     */
    public function inspect(): array
    {
        $activeSites = Site::query()
            ->where('status', 'active')
            ->whereNull('lifecycle_action')
            ->get();
        $recentChecks = SiteHealthCheck::query()
            ->whereIn('site_id', $activeSites->modelKeys())
            ->where('checked_at', '>=', now()->subHour())
            ->latest('checked_at')
            ->get()
            ->groupBy('site_id');

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
            'availability' => $activeSites
                ->filter(function (Site $site) use ($recentChecks): bool {
                    $checks = $recentChecks->get($site->id, collect())->take(2);

                    return $site->health_status === 'degraded'
                        || ($checks->count() === 2 && $checks->every(fn (SiteHealthCheck $check): bool => $check->status === 'failed'))
                        || ($site->health_checked_at && $site->health_checked_at->lt(now()->subMinutes(30)));
                })
                ->sortBy('health_checked_at')
                ->take(12)
                ->values(),
            'certificates' => $activeSites
                ->filter(fn (Site $site): bool => $site->ssl_enabled && $site->ssl_expires_at?->lte(now()->addDays(21)))
                ->sortBy('ssl_expires_at')
                ->take(12)
                ->values(),
        ];
    }
}
