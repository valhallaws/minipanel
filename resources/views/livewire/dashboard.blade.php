<div class="control-page dashboard-page hosting-desktop" x-data="{ activityOpen: false, search: '', expandedDomain: $persist(null).as('minipanel.expanded-domain.{{ auth()->id() }}') }" @if($activity->whereIn('status', ['queued', 'running'])->isNotEmpty() || $lifecycleSites->whereNull('lifecycle_error')->isNotEmpty()) wire:poll.1500ms @endif>
    <section class="hosting-toolbar">
        <div><small>{{ $sites->count() }} dominios en total</small><div class="hosting-actions"><button wire:click="$set('showCreate', true)" class="primary">Agregar dominio</button><button wire:click="$set('showCreate', true)" class="secondary">Agregar subdominio</button><button wire:click="$toggle('showTrash')" class="ghost">Papelera ({{ $trash->count() }})</button></div></div>
        <label><span class="sr-only">Buscar dominio</span><input x-model="search" placeholder="Buscar dominio…" type="search"></label>
    </section>
    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif
    @error('siteAction')<p class="error" role="alert">{{ $message }}</p>@enderror
    @include('livewire.domain-lifecycle')
    @if($showCreate)
    <div class="settings-backdrop"><section class="panel create settings-drawer" role="dialog" aria-modal="true" aria-label="Nuevo dominio"><div class="section-title"><div><h2>Nuevo dominio</h2><p>Se creará en <code>/var/www/tu-dominio</code> y se enviará al agente.</p></div><button wire:click="$set('showCreate', false)" class="ghost">Cerrar</button></div>
        <form wire:submit="createSite" class="form-grid">
            <label>Dominio<input wire:model="domain" placeholder="ejemplo.com o tienda.ejemplo.com" required></label>
            <label>Nombre descriptivo <small>opcional</small><input wire:model="name" placeholder="Mi dominio"></label>
            <label>Dominio padre<select wire:model="parentSiteId"><option value="">Dominio independiente</option>@foreach($domainTree as $parent)<option wire:key="parent-{{ $parent->id }}" value="{{ $parent->id }}">{{ $parent->domain }}</option>@endforeach</select></label>
            <p class="muted">Se preparan la carpeta y Nginx. Git, PHP, bases de datos y Laravel se configuran después desde las herramientas del dominio. Esto no compra ni registra el dominio ante un proveedor DNS.</p>
            <div class="form-actions"><button class="primary" type="submit" wire:loading.attr="disabled">Crear dominio</button></div>
        </form>
        @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    </section></div>
    @endif
    <section class="hosting-catalog" aria-label="Dominios">
        <div class="domain-columns"><span>Nombre de dominio</span><span>Estado</span><span>SSL</span></div>
        @forelse($domainTree as $site)
            <details class="domain-group" wire:key="domain-{{ $site->id }}" x-show="!search || $el.textContent.toLowerCase().includes(search.toLowerCase())" :open="expandedDomain === {{ $site->id }}" @toggle="if ($el.open) expandedDomain = {{ $site->id }}; else if (expandedDomain === {{ $site->id }}) expandedDomain = null">
                <summary class="domain-heading"><strong><x-ui-icon name="globe" /><span class="domain-identity">{{ $site->name ?: $site->domain }}<small>Dominio: {{ $site->domain }}@if($site->resourceDomain() !== $site->domain) · Alias del servidor: {{ $site->resourceDomain() }}@endif</small></span></strong>@include('livewire.domain-status', ['site' => $site])<span><a class="domain-external-link" href="https://{{ $site->domain }}" target="_blank" rel="noopener noreferrer" @click.stop aria-label="Abrir {{ $site->domain }} en otra pestaña"><x-ui-icon name="external" /></a>{{ $site->ssl_enabled ? 'Protegido' : 'Sin certificado' }} @include('livewire.domain-actions', ['site' => $site])</span></summary>
                @unless($site->lifecycle_action) @livewire('site-manager', ['site' => $site, 'embedded' => true], key('manager-'.$site->id.'-'.$site->domain)) @endunless
                @foreach($site->children as $child)
                    <div class="domain-child" wire:key="subdomain-{{ $child->id }}"><details :open="expandedDomain === {{ $child->id }}" @toggle="if ($el.open) expandedDomain = {{ $child->id }}; else if (expandedDomain === {{ $child->id }}) expandedDomain = null"><summary class="domain-heading"><strong><span class="domain-identity">{{ $child->name ?: $child->domain }}<small>Dominio: {{ $child->domain }}@if($child->resourceDomain() !== $child->domain) · Alias del servidor: {{ $child->resourceDomain() }}@endif</small></span></strong>@include('livewire.domain-status', ['site' => $child])<span><a class="domain-external-link" href="https://{{ $child->domain }}" target="_blank" rel="noopener noreferrer" @click.stop aria-label="Abrir {{ $child->domain }} en otra pestaña"><x-ui-icon name="external" /></a>{{ $child->ssl_enabled ? 'Protegido' : 'Sin certificado' }} @include('livewire.domain-actions', ['site' => $child])</span></summary>@unless($child->lifecycle_action) @livewire('site-manager', ['site' => $child, 'embedded' => true], key('manager-'.$child->id.'-'.$child->domain)) @endunless</details></div>
                @endforeach
            </details>
        @empty
            <div class="empty"><h3>Aún no hay dominios</h3><p>Agrega un dominio para preparar su alojamiento.</p><button wire:click="$set('showCreate', true)" class="secondary">Crear mi primer dominio</button></div>
        @endforelse
    </section>
    <section class="panel activity dashboard-activity" :class="{ expanded: activityOpen }"><button class="ghost" @click="activityOpen = !activityOpen" :aria-expanded="activityOpen">Actividad reciente ▾</button><div x-show="activityOpen" x-cloak><div class="section-title"><div><h2>Actividad reciente</h2><p>Todo cambio queda registrado.</p></div></div>
        @forelse($activity as $item)<div class="activity-row"><span class="dot {{ $item->status }}"></span><div><strong>{{ $item->action }}</strong> <span class="muted">en {{ $item->site->domain }}</span><p>{{ $item->output }}</p></div><time>{{ $item->created_at->diffForHumans() }}</time></div>@empty <p class="muted">Las operaciones del servidor aparecerán aquí.</p>@endforelse
    </div></section>
    @if($showTrash)
    <section class="panel domain-trash" aria-label="Papelera de dominios"><div class="section-title"><div><h2>Papelera</h2><p>Los dominios eliminados se conservan cinco días.</p></div><button class="ghost" wire:click="$set('showTrash', false)">Cerrar</button></div>
        @forelse($trash as $trashedSite)<div class="activity-row" wire:key="trash-{{ $trashedSite->id }}"><div><strong>{{ $trashedSite->domain }}</strong><p>Eliminación definitiva: {{ $trashedSite->purge_after?->format('d/m/Y H:i') }} UTC.</p></div><button class="secondary" wire:click="openSiteAction({{ $trashedSite->id }}, 'restore')" @disabled($trashedSite->lifecycle_action || $trashedSite->purge_after?->isPast())>Restaurar</button><button class="danger-button" wire:click="openSiteAction({{ $trashedSite->id }}, 'purge')" @disabled($trashedSite->lifecycle_action)>Eliminar ahora</button></div>@empty<p class="muted">La papelera está vacía.</p>@endforelse
    </section>
    @endif
</div>
