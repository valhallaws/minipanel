<?php

namespace App\Livewire;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ServerTerminal extends Component
{
    public bool $passwordConfirmed = false;

    public function mount(AuditLogger $audit): void
    {
        abort_unless(Auth::check(), 403);

        $this->passwordConfirmed = (int) session('auth.password_confirmed_at', 0) >= now()->subSeconds((int) config('auth.password_timeout', 10800))->timestamp;

        if ($this->passwordConfirmed) {
            $audit->record('server.root_terminal_opened');
        }
    }

    public function render()
    {
        return view('livewire.server-terminal')->layout('components.layouts.app');
    }
}
