<span x-data="{ statusMenu: false }" class="domain-status-menu" @click.stop @keydown.stop>
    <button type="button" class="ghost" @click.prevent="statusMenu = !statusMenu" :aria-expanded="statusMenu" aria-label="Estado de {{ $site->domain }}"><i class="status-light {{ $site->status }}"></i>{{ ['active' => 'Activo', 'suspended' => 'Suspendido', 'provisioning' => 'Preparando'][''.$site->status] ?? $site->status }} ▾</button>
    <span class="domain-status-options" x-show="statusMenu" x-cloak @click.outside="statusMenu = false">
        @if($site->status === 'active')<button type="button" wire:click="changeSiteStatus({{ $site->id }}, 'suspended')" @click="statusMenu = false" wire:loading.attr="disabled">Suspender</button>
        @elseif($site->status === 'suspended')<button type="button" wire:click="changeSiteStatus({{ $site->id }}, 'active')" @click="statusMenu = false" wire:loading.attr="disabled">Activar</button>
        @else<button type="button" wire:click="provision({{ $site->id }})" @click="statusMenu = false" wire:loading.attr="disabled">Reintentar preparación</button>@endif
    </span>
</span>
