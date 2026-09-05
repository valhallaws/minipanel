<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DnsPropagationChecker
{
    public function resolvers(): array
    {
        return [
            'Cloudflare' => 'https://cloudflare-dns.com/dns-query',
            'Google' => 'https://dns.google/resolve',
            'Quad9' => 'https://dns.quad9.net/dns-query',
        ];
    }

    public function checkResolver(string $domain, string $name, string $url): array
    {
        try {
            $answers = Http::accept('application/dns-json')->timeout(5)->get($url, ['name' => $domain, 'type' => 'A'])->json('Answer', []);
            $aaaa = Http::accept('application/dns-json')->timeout(5)->get($url, ['name' => $domain, 'type' => 'AAAA'])->json('Answer', []);

            return ['resolver' => $name, 'a' => collect($answers)->where('type', 1)->pluck('data')->implode(' '), 'aaaa' => collect($aaaa)->where('type', 28)->pluck('data')->implode(' ')];
        } catch (\Throwable) {
            return ['resolver' => $name, 'a' => '', 'aaaa' => '', 'error' => 'No disponible'];
        }
    }

    public function checkRecord(string $domain, string $recordType, string $name, string $url): array
    {
        try {
            $type = ['A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'NS' => 2][$recordType];
            $answers = Http::accept('application/dns-json')->timeout(5)->get($url, ['name' => $domain, 'type' => $recordType])->json('Answer', []);

            return ['resolver' => $name, 'answer' => collect($answers)->where('type', $type)->pluck('data')->implode(' ')];
        } catch (\Throwable) {
            return ['resolver' => $name, 'answer' => '', 'error' => 'No disponible'];
        }
    }
}
