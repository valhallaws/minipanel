<?php

namespace App\Console\Commands;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use Illuminate\Console\Command;

class CheckSiteHealth extends Command
{
    protected $signature = 'minipanel:check-site-health';

    protected $description = 'Programa comprobaciones periódicas de disponibilidad para los sitios activos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('minipanel.execution_enabled')) {
            return self::SUCCESS;
        }

        $queued = 0;

        Site::query()
            ->where('status', 'active')
            ->whereNull('lifecycle_action')
            ->whereDoesntHave('deployments', function ($query): void {
                $query->where('action', 'health')
                    ->whereIn('status', ['queued', 'running']);
            })
            ->orderBy('id')
            ->eachById(function (Site $site) use (&$queued): void {
                $deployment = Deployment::create([
                    'site_id' => $site->id,
                    'action' => 'health',
                    'parameters' => [
                        'arguments' => [$site->health_url ?: 'https://'.$site->domain],
                        'scheduled' => true,
                    ],
                    'status' => 'queued',
                    'output' => 'Comprobación periódica de disponibilidad programada.',
                ]);

                RunDeployment::dispatch($deployment->id);
                $queued++;
            });

        SiteHealthCheck::query()->where('checked_at', '<', now()->subDays(14))->delete();

        $this->info("Comprobaciones de disponibilidad puestas en cola: $queued");

        return self::SUCCESS;
    }
}
