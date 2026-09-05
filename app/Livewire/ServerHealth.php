<?php

namespace App\Livewire;

use App\Services\ServerReadiness;
use Livewire\Component;

class ServerHealth extends Component
{
    public array $checks = [];

    public string $checkedAt = '';

    public function mount(ServerReadiness $readiness): void
    {
        $this->refresh($readiness);
    }

    public function refresh(ServerReadiness $readiness): void
    {
        $this->checks = $readiness->inspect();
        $this->checkedAt = now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.server-health')->layout('components.layouts.app');
    }
}
