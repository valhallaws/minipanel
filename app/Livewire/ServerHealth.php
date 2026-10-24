<?php

namespace App\Livewire;

use App\Services\OperationsOverview;
use App\Services\ServerReadiness;
use Livewire\Component;

class ServerHealth extends Component
{
    public array $checks = [];

    public string $checkedAt = '';

    public array $operations = [];

    public function mount(ServerReadiness $readiness, OperationsOverview $operations): void
    {
        $this->refresh($readiness, $operations);
    }

    public function refresh(ServerReadiness $readiness, OperationsOverview $operations): void
    {
        $this->checks = $readiness->inspect();
        $this->operations = $operations->inspect();
        $this->checkedAt = now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.server-health')->layout('components.layouts.app');
    }
}
