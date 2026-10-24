<div class="settings-backdrop" x-show="drawer === 'monitor'" x-cloak @keydown.escape.window="drawer = ''">
    <section class="settings-drawer" role="dialog" aria-modal="true" aria-label="Monitorización del sitio">
        <header><strong>Monitorización · {{ $site->domain }}</strong><button class="ghost" @click="drawer = ''">Cerrar ✕</button></header>
        <div class="settings-body">
    <article class="panel" @if(($dnsRun && in_array($dnsRun->status, ['queued', 'running'])) || ($healthRun && in_array($healthRun->status, ['queued', 'running']))) wire:poll.1500ms @endif>
        <div class="section-title">
            <div>
                <h2>Disponibilidad, DNS y respaldos</h2>
                <p>Freyja revisa la disponibilidad de los sitios activos cada 10 minutos y conserva 14 días de historial.</p>
            </div>
        </div>

        <div class="stack">
            <input wire:model="healthUrl" placeholder="https://{{ $site->domain }}">
            <button wire:click="checkHealth" class="secondary" wire:loading.attr="disabled">Comprobar ahora</button>
            <button wire:click="checkDns" class="secondary" wire:loading.attr="disabled">Comprobar propagación DNS</button>
            <button wire:click="backup" class="secondary" wire:loading.attr="disabled">Crear backup de archivos</button>
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
            </div>
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

        <p class="muted">Backup: {{ $site->last_backup_at?->diffForHumans() ?? 'nunca' }}</p>
        @if($site->last_backup_path)
            <form wire:submit="restoreBackup" class="stack">
                <label>Para restaurar el último backup escribe <code>RESTAURAR {{ $site->domain }}</code><input wire:model="restoreConfirmation" autocomplete="off"></label>
                <button class="ghost">Restaurar último backup</button>
            </form>
        @endif

        @if($dnsRun)
            <p class="muted">DNS: {{ $dnsRun->status }} @if(in_array($dnsRun->status, ['queued', 'running']))<button wire:click="cancelDns({{ $dnsRun->id }})" class="ghost">Cancelar</button>@endif</p>
            <div class="dns-results">@foreach($dnsRun->results['checks'] ?? [] as $check)<p><code>{{ $check['resolver'] }}</code> · A: {{ $check['a'] ?: '—' }} · AAAA: {{ $check['aaaa'] ?: '—' }}</p>@endforeach</div>
        @elseif($site->dns_results)
            <div class="dns-results">@foreach($site->dns_results['checks'] ?? [] as $check)<p><code>{{ $check['resolver'] }}</code> · A: {{ $check['a'] ?: '—' }} · AAAA: {{ $check['aaaa'] ?: '—' }}</p>@endforeach</div>
        @endif
    </article>
        </div>
        <footer><span class="muted">Las comprobaciones periódicas no cambian el sitio.</span></footer>
    </section>
</div>
