<div>
    <header class="topbar">
        <a class="brand" href="/"><span class="mark">MP</span> MiniPanel</a>
        <div class="server"><i></i> VPS conectado <span>Ubuntu · producción</span></div>
        <a class="ghost" href="{{ route('tools.dns') }}">DNS</a><a class="ghost" href="{{ route('server.health') }}">Salud</a><a class="ghost" href="{{ route('server.setup') }}">Servidor</a><a class="ghost" href="{{ route('security.settings') }}">Seguridad</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="ghost">Salir</button></form>
    </header>
    <section class="hero">
        <div><p class="eyebrow">APLICACIONES Y SITIOS</p><h1>Tu VPS, sin fricción.</h1><p class="muted">Despliega, protege y observa tus proyectos desde un solo lugar.</p></div>
        <button wire:click="$toggle('showCreate')" class="primary">+ Nuevo sitio</button>
    </section>
    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif
    @if($showCreate)
    <section class="panel create"><div class="section-title"><div><h2>Nuevo sitio</h2><p>Se creará en <code>/var/www/tu-dominio</code> y se enviará al agente.</p></div><button wire:click="$set('showCreate', false)" class="ghost">Cerrar</button></div>
        <form wire:submit="createSite" class="form-grid">
            <label>Nombre<input wire:model="name" placeholder="Mi aplicación" required></label>
            <label>Dominio<input wire:model="domain" placeholder="app.ejemplo.com" required></label>
            <label>Conexión Git<select wire:model.live="repositoryProtocol"><option value="https">HTTPS</option><option value="ssh">SSH · Deploy Key</option></select></label>
            <label>Repositorio Git <small>opcional</small><input wire:model="repository" placeholder="{{ $repositoryProtocol === 'ssh' ? 'git@github.com:tu-org/app.git' : 'https://github.com/tu-org/app.git' }}"></label>
            <label>Rama<input wire:model="branch" placeholder="main" required></label>
            <label>Tipo<select wire:model="runtime"><option value="laravel">Laravel / PHP</option><option value="static">Estático / Vite</option></select></label>
            <label>PHP<select wire:model="phpVersion"><option>8.4</option><option>8.3</option><option>8.2</option></select></label>
            @if($runtime === 'laravel')<fieldset class="form-check"><legend>Extensiones PHP</legend>@foreach(['bcmath','curl','mbstring','mysql','xml','zip','gd','intl','redis','soap','imagick'] as $extension)<label class="check"><input wire:model="phpExtensions" value="{{ $extension }}" type="checkbox"> {{ $extension }}</label>@endforeach</fieldset>@endif
            <label class="check form-check"><input wire:model="queueEnabled" type="checkbox"> Activar worker de cola</label>
            <div class="form-actions"><button class="primary" type="submit">Crear y aprovisionar</button></div>
        </form>
        @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    </section>
</div>
    @endif
    <section class="stats">
        <div class="stat"><span>Sitios</span><strong>{{ $sites->count() }}</strong></div>
        <div class="stat"><span>SSL activos</span><strong>{{ $sites->where('ssl_enabled', true)->count() }}</strong></div>
        <div class="stat"><span>Workers</span><strong>{{ $sites->where('queue_enabled', true)->count() }}</strong></div>
        <div class="stat"><span>En cola</span><strong>{{ $activity->where('status', 'queued')->count() }}</strong></div>
    </section>
    <section class="panel"><div class="section-title"><div><h2>Sitios</h2><p>Operaciones disponibles: provisionar, desplegar y emitir certificados.</p></div></div>
        @forelse($sites as $site)
        <article class="site-row" wire:key="site-{{ $site->id }}"><div class="site-icon">{{ strtoupper(substr($site->name, 0, 1)) }}</div><div class="site-info"><h3>{{ $site->name }} <span class="badge {{ $site->status }}">{{ $site->status }}</span></h3><a href="https://{{ $site->domain }}" target="_blank">{{ $site->domain }} ↗</a><p>{{ $site->path }} · PHP {{ $site->php_version }} · {{ $site->branch }}</p></div><div class="site-options">@if($site->ssl_enabled)<span class="ok">● SSL activo</span>@else<button wire:click="issueCertificate({{ $site->id }})" class="tiny">Emitir SSL</button>@endif @if($site->queue_enabled)<span class="muted">Worker</span>@endif</div><a href="{{ route('sites.show', $site) }}" class="secondary">Administrar</a><button wire:click="deploy({{ $site->id }})" class="secondary">Desplegar</button></article>
        @empty <div class="empty"><div class="empty-art">⌘</div><h3>Aún no hay sitios</h3><p>Crea tu primer sitio y el panel generará su configuración segura.</p><button wire:click="$set('showCreate', true)" class="secondary">Crear mi primer sitio</button></div>@endforelse
    </section>
    <section class="panel activity"><div class="section-title"><div><h2>Actividad reciente</h2><p>Todo cambio queda registrado.</p></div></div>
        @forelse($activity as $item)<div class="activity-row"><span class="dot {{ $item->status }}"></span><div><strong>{{ $item->action }}</strong> <span class="muted">en {{ $item->site->domain }}</span><p>{{ $item->output }}</p></div><time>{{ $item->created_at->diffForHumans() }}</time></div>@empty <p class="muted">Las operaciones del servidor aparecerán aquí.</p>@endforelse
    </section>
</div>
