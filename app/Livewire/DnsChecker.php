<?php

namespace App\Livewire;

use App\Jobs\RunGlobalDnsCheck;
use App\Models\GlobalDnsCheck;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DnsChecker extends Component
{
    public string $domain = '';

    public string $recordType = 'A';

    public string $expectedTarget = '';

    public ?int $checkId = null;

    /** Start exactly one on-demand measurement. No recurring check is created. */
    public function run(): void
    {
        if ($this->activeCheck()) {
            return;
        }

        $data = $this->validate([
            'domain' => ['required', 'lowercase', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'],
            'recordType' => [Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'])],
            'expectedTarget' => ['nullable', 'string', 'max:255'],
        ]);

        $check = GlobalDnsCheck::create([
            'domain' => $data['domain'],
            'record_type' => $data['recordType'],
            'expected_target' => filled($data['expectedTarget']) ? trim($data['expectedTarget']) : null,
        ]);

        $this->checkId = $check->id;
        RunGlobalDnsCheck::dispatch($check->id);
    }

    public function cancel(): void
    {
        if ($this->checkId) {
            GlobalDnsCheck::whereKey($this->checkId)
                ->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
    }

    public function render()
    {
        $check = $this->checkId ? GlobalDnsCheck::find($this->checkId) : null;

        return view('livewire.dns-checker', [
            'check' => $check,
            'isRunning' => $check && in_array($check->status, ['queued', 'running'], true),
        ])->layout('components.layouts.app');
    }

    private function activeCheck(): bool
    {
        return $this->checkId && GlobalDnsCheck::query()
            ->whereKey($this->checkId)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
