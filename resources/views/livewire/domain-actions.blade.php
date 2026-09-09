<span x-data="{ actionsOpen: false }" class="domain-status-menu" @click.stop @keydown.stop>
    <button type="button" class="ghost" @click.prevent="actionsOpen = !actionsOpen" :aria-expanded="actionsOpen" aria-label="Acciones de {{ $site->domain }}">⋮</button>
    <span class="domain-status-options" x-show="actionsOpen" x-cloak @click.outside="actionsOpen = false">
        <button type="button" wire:click="openSiteAction({{ $site->id }}, 'rename')" @click.prevent="actionsOpen = false" @disabled($site->lifecycle_action)>Renombrar dominio</button>
        <button type="button" wire:click="openSiteAction({{ $site->id }}, 'delete')" @click.prevent="actionsOpen = false" @disabled($site->lifecycle_action)>Eliminar dominio</button>
    </span>
</span>
