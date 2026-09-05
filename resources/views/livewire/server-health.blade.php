<div>
    <header class="topbar"><a class="brand" href="/"><span class="mark">MP</span> MiniPanel</a><div class="server">Salud del servidor</div><a class="ghost" href="{{ route('server.setup') }}">Configuración</a><a class="ghost" href="/">← Sitios</a></header>
    <section class="hero"><div><p class="eyebrow">SOLO LECTURA</p><h1>Estado del servidor</h1><p class="muted">Comprueba dependencias y configuración sin cambiar el host.</p></div><button wire:click="refresh" class="primary">Actualizar</button></section>
    <section class="panel"><div class="section-title"><div><h2>Preflight</h2><p>Revisado a las {{ $checkedAt }}</p></div></div>
        <div class="readiness-list">@foreach($checks as $check)<div><span class="dot {{ $check['status'] === 'ok' ? 'finished' : 'failed' }}"></span><strong>{{ $check['name'] }}</strong><span class="muted">{{ $check['detail'] }}</span></div>@endforeach</div>
    </section>
</div>
