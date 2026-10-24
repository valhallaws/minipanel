<?php

namespace App\Console\Commands;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'minipanel:run-scheduled-backups';

    protected $description = 'Programa los respaldos diarios de los sitios activos';

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
            ->where('backup_enabled', true)
            ->whereNull('lifecycle_action')
            ->whereDoesntHave('deployments', function ($query): void {
                $query->whereIn('action', ['backup', 'verify-backup', 'prune-backups'])
                    ->whereIn('status', ['queued', 'running']);
            })
            ->orderBy('id')
            ->eachById(function (Site $site) use (&$queued): void {
                $deployment = Deployment::create([
                    'site_id' => $site->id,
                    'action' => 'backup',
                    'parameters' => ['scheduled' => true],
                    'status' => 'queued',
                    'output' => 'Respaldo diario programado.',
                ]);

                RunDeployment::dispatch($deployment->id);
                $queued++;
            });

        $this->info("Respaldos diarios puestos en cola: $queued");

        return self::SUCCESS;
    }
}
