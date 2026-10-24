<div class="settings-backdrop" x-show="drawer === 'monitor'" x-cloak @keydown.escape.window="drawer = ''">
    <section class="settings-drawer" role="dialog" aria-modal="true" aria-label="Monitorización del sitio">
        <header><strong>Monitorización · {{ $site->domain }}</strong><button class="ghost" @click="drawer = ''">Cerrar ✕</button></header>
        <div class="settings-body">
    <article class="panel" @if(($dnsRun && in_array($dnsRun->status, ['queued', 'running'])) || ($healthRun && in_array($healthRun->status, ['queued', 'running']))) wire:poll.1500ms @endif>
        <div class="section-title">
            <div>
                <h2>Disponibilidad</h2>
                <p>Freyja revisa el sitio cada 10 minutos y conserva 14 días de historial.</p>
            </div>
        </div>

        @php($latestHealthCheck = $healthChecks->first())
        <div class="site-health-status">
            <span @class(['dot', 'finished' => $latestHealthCheck?->status === 'healthy', 'failed' => $latestHealthCheck && $latestHealthCheck->status !== 'healthy'])></span>
            <div>
                <strong>{{ $latestHealthCheck?->status === 'healthy' ? 'Disponible' : ($latestHealthCheck?->status === 'degraded' ? 'Disponible, pero lenta' : ($latestHealthCheck?->status === 'failed' ? 'Sin respuesta' : 'Sin revisar')) }}</strong>
                <p>
                    @if($latestHealthCheck)
                        {{ $latestHealthCheck->status_code ? 'HTTP '.$latestHealthCheck->status_code : 'Sin código HTTP' }}
                        @if($latestHealthCheck->response_time_ms !== null) · {{ $latestHealthCheck->response_time_ms }} ms @endif
                        · {{ $latestHealthCheck->checked_at->diffForHumans() }}
                    @else
                        Se revisará automáticamente cuando corra el monitor.
                    @endif
                </p>
                @if($site->ssl_enabled)
                    <p class="monitor-certificate">SSL: {{ $site->ssl_expires_at ? 'vence '.$site->ssl_expires_at->diffForHumans() : 'pendiente de detectar' }}</p>
                @endif
            </div>
        </div>

        <div class="monitor-check-form">
            <label>Endpoint a comprobar<input wire:model="healthUrl" placeholder="https://{{ $site->domain }}"></label>
            <button wire:click="checkHealth" class="primary" wire:loading.attr="disabled">Comprobar ahora</button>
        </div>

        <details class="health-check-history">
            <summary>Ver últimas {{ $healthChecks->count() }} comprobaciones</summary>
            <div>
                @forelse($healthChecks as $check)
                    <p wire:key="health-check-{{ $check->id }}"><span @class(['dot', 'finished' => $check->status === 'healthy', 'failed' => $check->status !== 'healthy'])></span>{{ $check->checked_at->format('d/m H:i') }} · {{ $check->status_code ? 'HTTP '.$check->status_code : 'sin respuesta' }}@if($check->response_time_ms !== null) · {{ $check->response_time_ms }} ms @endif @if($check->error) · {{ $check->error }} @endif</p>
                @empty
                    <p class="muted">Aún no hay comprobaciones registradas.</p>
                @endforelse
            </div>
        </details>

        <details class="monitor-diagnostics">
            <summary>Diagnóstico avanzado</summary>
            <p class="muted">Comprueba si el dominio ya propagó correctamente en resolvers públicos.</p>
            <button wire:click="checkDns" class="secondary" wire:loading.attr="disabled">Comprobar propagación DNS</button>
            @if($dnsRun)
                <div class="dns-results">@foreach($dnsRun->results['checks'] ?? [] as $check)<p><code>{{ $check['resolver'] }}</code> · A: {{ $check['a'] ?: '—' }} · AAAA: {{ $check['aaaa'] ?: '—' }}</p>@endforeach</div>
            @elseif($site->dns_results)
                <div class="dns-results">@foreach($site->dns_results['checks'] ?? [] as $check)<p><code>{{ $check['resolver'] }}</code> · A: {{ $check['a'] ?: '—' }} · AAAA: {{ $check['aaaa'] ?: '—' }}</p>@endforeach</div>
            @endif
        </details>
    </article>
        </div>
        <footer><span class="muted">Las comprobaciones periódicas no cambian el sitio.</span><a class="ghost" href="{{ route('sites.backups', $site) }}">Ver respaldos →</a></footer>
    </section>
</div>
