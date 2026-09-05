<?php

namespace App\Jobs;

use App\Models\DnsCheckRun;
use App\Services\DnsPropagationChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunDnsPropagationCheck implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public int $runId) {}

    public function handle(DnsPropagationChecker $checker): void
    {
        $run = DnsCheckRun::with('site')->findOrFail($this->runId);
        if ($run->status === 'cancelled') {
            return;
        }
        $run->update(['status' => 'running', 'results' => ['domain' => $run->site->domain, 'checks' => []]]);
        foreach ($checker->resolvers() as $name => $url) {
            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }
            $results = $run->results;
            $results['checks'][] = $checker->checkResolver($run->site->domain, $name, $url);
            $run->update(['results' => $results]);
        }
        $run->update(['status' => 'completed', 'finished_at' => now()]);
        $run->site->update(['dns_results' => $run->results, 'dns_checked_at' => now()]);
    }
}
