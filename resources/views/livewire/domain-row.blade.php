<article class="site-row" wire:key="site-row-{{ $site->id }}">
    <div class="site-info">
        <h3>{{ $site->domain }}</h3>
        <p>{{ $site->path }}</p>
        <span class="badge">{{ $site->status }}</span>
    </div>
    <a href="{{ route('sites.files', $site) }}" class="ghost">Archivos</a>
    <a href="{{ route('sites.show', $site) }}" class="secondary">Herramientas →</a>
    @if($site->status === 'provisioning')
        <button wire:click="provision({{ $site->id }})" wire:loading.attr="disabled" class="ghost">Reintentar preparación</button>
    @endif
</article>
