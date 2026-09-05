<?php

namespace App\Jobs;

use App\Models\GlobalDnsCheck;
use App\Services\GlobalpingDnsChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunGlobalDnsCheck implements ShouldQueue
{
    use Queueable;

    public int $timeout = 75;

    public int $tries = 1;

    public function __construct(public int $checkId) {}

    public function handle(GlobalpingDnsChecker $globalping): void
    {
        $check = GlobalDnsCheck::findOrFail($this->checkId);

        if ($check->status === 'cancelled') {
            return;
        }

        try {
            $measurement = $globalping->start($check->domain, $check->record_type);
            $measurementId = $measurement['id'];

            $checks = $globalping->results($measurement, $check->expected_target);
            $check->update([
                'status' => 'running',
                'results' => [
                    'provider' => 'Globalping',
                    'measurement_id' => $measurementId,
                    'checks' => $checks,
                    'summary' => $globalping->summary($checks, $check->expected_target),
                ],
            ]);

            $deadline = now()->addSeconds(65);
            do {
                sleep(1);
                $check->refresh();

                // Globalping does not expose a cancellation endpoint. This stops
                // local polling; the already-created one-off measurement expires remotely.
                if ($check->status === 'cancelled') {
                    return;
                }

                $measurement = $globalping->status($measurementId);
                $checks = $globalping->results($measurement, $check->expected_target);
                $check->update([
                    'results' => [
                        'provider' => 'Globalping',
                        'measurement_id' => $measurementId,
                        'checks' => $checks,
                        'summary' => $globalping->summary($checks, $check->expected_target),
                    ],
                ]);
            } while (($measurement['status'] ?? 'in-progress') === 'in-progress' && now()->lt($deadline));

            $check->update([
                'status' => ($measurement['status'] ?? null) === 'finished' ? 'completed' : 'failed',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $check->update([
                'status' => 'failed',
                'results' => ['provider' => 'Globalping', 'error' => $exception->getMessage(), 'checks' => []],
                'finished_at' => now(),
            ]);
        }
    }
}
