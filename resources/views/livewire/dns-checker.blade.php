<div @if($isRunning) wire:poll.1500ms @endif>
    <header class="topbar"><a class="brand" href="/"><span class="mark">MP</span> MiniPanel</a><div class="server">Herramientas</div><a class="ghost" href="/">← Panel</a></header>
    <section class="hero"><div><p class="eyebrow">HERRAMIENTA INDEPENDIENTE · GLOBALPING</p><h1>DNS Checker</h1><p class="muted">Cada medición pide hasta 8 sondas en México y 2 por continente. No monitorea ni vuelve a consultar por sí sola.</p></div></section>
    <section class="panel dns-tool">
        <form wire:submit="run" class="dns-form">
            <label>Dominio<input wire:model="domain" placeholder="ejemplo.com" autofocus @disabled($isRunning)></label>
            <label>Registro<select wire:model="recordType" @disabled($isRunning)>@foreach(['A','AAAA','CNAME','MX','TXT','NS'] as $type)<option>{{ $type }}</option>@endforeach</select></label>
            <label>Target esperado <small>(opcional)</small><input wire:model="expectedTarget" placeholder="65.99.225.120" @disabled($isRunning)></label>
            <button class="primary" @disabled($isRunning)>{{ $isRunning ? 'Consultando…' : 'Consultar ahora' }}</button>
        </form>
        @error('domain')<p class="error">{{ $message }}</p>@enderror @error('expectedTarget')<p class="error">{{ $message }}</p>@enderror
    </section>
    @if($check)
        <section class="panel dns-live">
            <div class="section-title"><div><h2>{{ $check->domain }} <span class="badge">{{ $check->record_type }}</span></h2><p>Estado: {{ $check->status }}@if($check->results['measurement_id'] ?? null) · Globalping #{{ $check->results['measurement_id'] }}@endif</p></div>@if($isRunning)<button wire:click="cancel" class="ghost">Dejar de esperar</button>@endif</div>
            @if($check->results['error'] ?? null)<p class="error">{{ $check->results['error'] }}</p>@endif
            @if($check->expected_target)
                @php($summary = $check->results['summary'] ?? [])
                <p class="dns-compliance">Target: <code>{{ $check->expected_target }}</code> · Coinciden <strong>{{ $summary['matches'] ?? 0 }}/{{ $summary['responded'] ?? 0 }}</strong> sondas @if(($summary['compliance_percent'] ?? null) !== null)· <strong>{{ $summary['compliance_percent'] }}% de cumplimiento</strong>@endif</p>
            @endif
            @php($encodedChecks = base64_encode(json_encode($check->results['checks'] ?? [])))
            <div data-dns-map-payload="{{ $check->id }}" data-checks="{{ $encodedChecks }}" hidden></div>
            <div class="dns-results-grid" wire:key="dns-results-run-{{ $check->id }}">
                <div class="dns-map" data-dns-map-for="{{ $check->id }}" wire:ignore x-data x-init="$nextTick(() => window.initDnsMap($refs.map, '{{ $check->id }}'))">
                    <div x-ref="map" class="dns-map-canvas" aria-label="Mapa de respuestas DNS por sonda"></div>
                    <p class="dns-map-legend"><span class="ok"></span> {{ $check->expected_target ? 'Coincide con target' : 'Respuesta correcta' }} <span class="pending"></span> En curso <span class="failed"></span> {{ $check->expected_target ? 'No coincide' : 'Sin respuesta o error' }}</p>
                </div>
                <div class="dns-list">@forelse($check->results['checks'] ?? [] as $result)<div><strong>{{ $result['location'] ?? $result['resolver'] }}</strong><span>{{ $result['answer'] ?: ($result['status_code'] ?? $result['error'] ?? 'Sin respuesta') }}@if($check->expected_target && $result['status'] === 'finished') · <b class="{{ $result['matches_expected'] ? 'match' : 'mismatch' }}">{{ $result['matches_expected'] ? 'Coincide' : 'No coincide' }}</b>@endif @if(isset($result['time_ms'])) · {{ $result['time_ms'] }} ms @endif</span></div>@empty<p class="muted">Solicitando sondas globales…</p>@endforelse</div>
            </div>
        </section>
    @endif
</div>
