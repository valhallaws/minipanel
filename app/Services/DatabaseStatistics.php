<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DatabaseStatistics
{
    public static function forget(Site $site): void
    {
        Cache::forget('database-statistics-'.$site->id);
    }

    /** @return array<string, array{bytes: int, tables: int}> */
    public function read(Site $site): array
    {
        if (! config('minipanel.execution_enabled')) {
            throw new RuntimeException('Execution disabled');
        }

        return Cache::remember('database-statistics-'.$site->id, 30, function () use ($site): array {
            $names = $site->databases()->pluck('name')->all();
            if ($names === []) {
                return [];
            }
            $result = Process::timeout(15)->input(json_encode(['statistics' => true, 'databases' => $names], JSON_THROW_ON_ERROR)."\n")->run([
                'sudo', '/usr/local/bin/minipanel-agent', 'database-sync', $site->path, $site->resourceDomain(),
                '', 'main', $site->php_version, '0', '0', 'static', '',
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('Statistics unavailable');
            }
            $statistics = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($statistics)) {
                throw new RuntimeException('Invalid statistics');
            }
            $scoped = [];
            foreach ($names as $name) {
                $entry = $statistics[$name] ?? null;
                if (is_array($entry) && is_int($entry['bytes'] ?? null) && is_int($entry['tables'] ?? null) && $entry['bytes'] >= 0 && $entry['tables'] >= 0) {
                    $scoped[$name] = $entry;
                }
            }

            return $scoped;
        });
    }
}
