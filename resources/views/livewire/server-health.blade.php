<div>
    <header class="topbar"><a class="brand" href="/"><img class="freyja-brand-mark" src="/brand/freyja-isotipo.png" alt="">Freyja</a><div class="server">Salud del servidor</div><a class="ghost" href="{{ route('server.setup') }}">Configuración</a><a class="ghost" href="/">← Sitios</a></header>
    <section class="hero"><div><p class="eyebrow">SOLO LECTURA</p><h1>Estado del servidor</h1><p class="muted">Comprueba dependencias y configuración sin cambiar el host.</p></div><button wire:click="refresh" class="primary">Actualizar</button></section>
    <section class="health-overview">
        <section class="panel health-preflight"><div class="section-title"><div><h2>Preflight</h2><p>Revisado a las {{ $checkedAt }}</p></div></div>
            <div class="readiness-list">@foreach($checks as $check)<div><span @class(['dot', 'finished' => $check['status'] === 'ok', 'failed' => $check['status'] === 'failed'])></span><strong>{{ $check['name'] }}</strong><span class="muted">{{ $check['detail'] }}</span></div>@endforeach</div>
        </section>
        <section class="panel health-kpis"><div class="section-title"><div><h2>Salud operativa</h2><p>Acciones activas y señales que requieren atención.</p></div></div>
            <div class="operations-summary">
                <article><strong>{{ count($operations['active'] ?? []) }}</strong><span>En curso</span></article>
                <article @class(['needs-attention' => count($operations['stalled'] ?? []) > 0])><strong>{{ count($operations['stalled'] ?? []) }}</strong><span>Detenidas</span></article>
                <article @class(['needs-attention' => count($operations['failed'] ?? []) > 0])><strong>{{ count($operations['failed'] ?? []) }}</strong><span>Fallos 24 h</span></article>
                <article @class(['needs-attention' => count($operations['lifecycle'] ?? []) > 0])><strong>{{ count($operations['lifecycle'] ?? []) }}</strong><span>Ciclo de vida</span></article>
                <article @class(['needs-attention' => count($operations['backups'] ?? []) > 0])><strong>{{ count($operations['backups'] ?? []) }}</strong><span>Respaldos</span></article>
                <article @class(['needs-attention' => count($operations['availability'] ?? []) > 0])><strong>{{ count($operations['availability'] ?? []) }}</strong><span>Disponibilidad</span></article>
            </div>
        </section>
    </section>
    <section class="panel operations-overview"><div class="section-title"><div><h2>Detalle de operación</h2><p>Desplázate dentro de cada tarjeta para revisar los registros.</p></div></div>
        <div class="operations-grid">
            <section><h3>Operación en curso</h3>@forelse($operations['active'] ?? [] as $operation)<div class="operation-row"><span class="dot {{ $operation->status }}"></span><div><strong>{{ str($operation->action)->replace('-', ' ') }}</strong><p><a href="{{ route('sites.show', $operation->site) }}">{{ $operation->site->domain }}</a> · {{ $operation->created_at->diffForHumans() }}</p></div></div>@empty<p class="muted">No hay acciones pendientes.</p>@endforelse</section>
            <section><h3>Operación detenida</h3>@forelse($operations['stalled'] ?? [] as $operation)<div class="operation-row"><span class="dot failed"></span><div><strong>{{ str($operation->action)->replace('-', ' ') }}</strong><p><a href="{{ route('sites.show', $operation->site) }}">{{ $operation->site->domain }}</a> · inició {{ $operation->started_at?->diffForHumans() ?? $operation->created_at->diffForHumans() }}</p></div></div>@empty<p class="muted">No hay operaciones atoradas.</p>@endforelse</section>
            <section><h3>Requiere atención</h3><p class="muted">Sólo fallos de las últimas 24 horas. El historial permanece en cada sitio.</p>@forelse($operations['failed'] ?? [] as $operation)<div class="operation-row"><span class="dot failed"></span><div><strong>{{ str($operation->action)->replace('-', ' ') }}</strong><p><a href="{{ route('sites.show', $operation->site) }}">Abrir {{ $operation->site->domain }}</a> · {{ $operation->finished_at?->diffForHumans() ?? $operation->created_at->diffForHumans() }}</p><p>{{ str($operation->output)->squish()->limit(130) }}</p></div></div>@empty<p class="muted">Sin fallos recientes.</p>@endforelse</section>
            <section><h3>Error de ciclo de vida</h3>@forelse($operations['lifecycle'] ?? [] as $site)<div class="operation-row"><span class="dot failed"></span><div><strong><a href="{{ route('dashboard') }}">{{ $site->domain }}</a></strong><p>{{ str($site->lifecycle_error)->squish()->limit(130) }}</p></div></div>@empty<p class="muted">No hay dominios detenidos.</p>@endforelse</section>
            <section><h3>Respaldo pendiente</h3>@forelse($operations['backups'] ?? [] as $site)<div class="operation-row"><span class="dot failed"></span><div><strong><a href="{{ route('sites.backups', $site) }}">{{ $site->domain }}</a></strong><p>{{ $site->last_backup_at ? 'Último: '.$site->last_backup_at->diffForHumans() : 'Aún no tiene un respaldo.' }}</p></div></div>@empty<p class="muted">Todos los sitios activos tienen respaldo reciente.</p>@endforelse</section>
            <section><h3>Disponibilidad</h3>@forelse($operations['availability'] ?? [] as $site)<div class="operation-row"><span class="dot failed"></span><div><strong><a href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a></strong><p>{{ $site->health_status === 'degraded' ? 'Respuesta lenta' : ($site->health_status === 'failed' ? 'Sin respuesta' : 'Sin comprobación reciente') }}{{ $site->health_checked_at ? ' · '.$site->health_checked_at->diffForHumans() : '' }}</p></div></div>@empty<p class="muted">Los sitios comprobados responden normalmente.</p>@endforelse</section>
        </div>
    </section>
</div>
