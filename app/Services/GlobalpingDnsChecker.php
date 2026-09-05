<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GlobalpingDnsChecker
{
    private const CONTINENTS = [
        'North America',
        'South America',
        'Europe',
        'Asia',
        'Africa',
        'Oceania',
    ];

    /**
     * Creates one Globalping DNS measurement. It is intentionally called only
     * from the user's explicit "Consultar ahora" action.
     */
    public function start(string $domain, string $recordType): array
    {
        $response = $this->client()->post('/v1/measurements', [
            'type' => 'dns',
            'target' => $domain,
            'inProgressUpdates' => true,
            // México is the decision probe for this panel. The remaining
            // locations provide context, but never replace a Mexican result.
            'locations' => array_merge(
                [['magic' => 'Mexico', 'limit' => 8]],
                collect(self::CONTINENTS)
                    ->map(fn (string $continent) => ['magic' => $continent, 'limit' => 2])
                    ->all(),
            ),
            'timeout' => 10,
            'measurementOptions' => [
                'query' => ['type' => $recordType],
                'protocol' => 'UDP',
            ],
        ])->throw();

        return $response->json();
    }

    public function status(string $measurementId): array
    {
        return $this->client()->get("/v1/measurements/{$measurementId}")->throw()->json();
    }

    public function results(array $measurement, ?string $expectedTarget = null): array
    {
        return collect($measurement['results'] ?? [])
            ->map(function (array $item) use ($expectedTarget): array {
                $probe = $item['probe'] ?? [];
                $result = $item['result'] ?? [];
                $answers = collect($result['answers'] ?? [])->pluck('value')->filter()->values();

                return [
                    'resolver' => $result['resolver'] ?? collect($probe['resolvers'] ?? [])->first() ?? 'Resolver no informado',
                    'location' => collect([$probe['city'] ?? null, $probe['country'] ?? null])->filter()->implode(', ') ?: 'Ubicación no informada',
                    'continent' => $probe['continent'] ?? null,
                    'country' => $probe['country'] ?? null,
                    'latitude' => $probe['latitude'] ?? null,
                    'longitude' => $probe['longitude'] ?? null,
                    'network' => $probe['network'] ?? null,
                    'status' => $result['status'] ?? 'in-progress',
                    'answer' => $answers->implode(' ') ?: '',
                    'status_code' => $result['statusCodeName'] ?? null,
                    'time_ms' => data_get($result, 'timings.total'),
                    'error' => $result['failureSource'] ?? null,
                    'expected_target' => $expectedTarget,
                    'matches_expected' => $expectedTarget === null ? null : $answers
                        ->contains(fn (string $answer) => $this->normalizeAnswer($answer) === $this->normalizeAnswer($expectedTarget)),
                ];
            })
            ->values()
            ->all();
    }

    public function summary(array $checks, ?string $expectedTarget): array
    {
        $finished = collect($checks)->where('status', 'finished');
        $matches = $expectedTarget === null ? 0 : $finished->where('matches_expected', true)->count();

        return [
            'expected_target' => $expectedTarget,
            'responded' => $finished->count(),
            'matches' => $matches,
            'compliance_percent' => $finished->isEmpty() || $expectedTarget === null ? null : round(($matches / $finished->count()) * 100),
        ];
    }

    private function normalizeAnswer(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }

    private function client(): PendingRequest
    {
        $client = Http::baseUrl('https://api.globalping.io')
            ->acceptJson()
            ->withUserAgent('MiniPanel DNS Checker/1.0')
            ->timeout(15)
            ->retry(1, 250, throw: false);

        if ($token = config('services.globalping.token')) {
            $client = $client->withToken($token);
        }

        return $client;
    }
}
