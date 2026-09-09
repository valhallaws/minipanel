<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class CaptureSiteSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $timeout = 65;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $site = Site::findOrFail($this->siteId);
        try {
            if (! config('minipanel.execution_enabled') || $site->status !== 'active') {
                throw new \RuntimeException('Snapshot unavailable');
            }
            $result = Process::timeout(55)->run([
                'sudo', '/usr/local/bin/minipanel-agent', 'snapshot', $site->path, $site->resourceDomain(),
                '', 'main', $site->php_version, '0', '0', 'static', '', $site->ssl_enabled ? 'https' : 'http',
            ]);
            $image = base64_decode(trim($result->output()), true);
            if (! $result->successful() || $image === false || strlen($image) > 2097152 || ! str_starts_with($image, "\xff\xd8\xff")) {
                throw new \RuntimeException('Invalid snapshot');
            }
            if (! Storage::disk('local')->put('site-snapshots/'.$site->id.'.jpg', $image)) {
                throw new \RuntimeException('Snapshot storage unavailable');
            }
            Cache::put('site-snapshot-'.$site->id, ['status' => 'ready', 'version' => time()], now()->addDays(7));
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::put('site-snapshot-'.$this->siteId, ['status' => 'failed'], now()->addHour());
    }
}
