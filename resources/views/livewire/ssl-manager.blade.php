<div class="ssl-manager {{ $embedded ? 'ssl-manager-embedded' : '' }}">
    <p class="eyebrow">SSL / TLS</p>
    <div class="section-title"><div><h1>Certificado para {{ $site->domain }}</h1><p>Estado, cobertura y renovación del certificado de este dominio.</p></div>@unless($embedded)<a class="secondary" href="{{ route('sites.show', $site) }}">← Sitio</a>@endunless</div>
    @if(session('notice'))<p class="notice" role="status">{{ session('notice') }}</p>@endif

    <section class="panel">
        <div class="form-grid">
            <div><strong>Let's Encrypt</strong><p class="muted">Validación y renovación automática.</p></div>
            <div><strong>{{ $site->ssl_enabled ? 'Certificado emitido' : 'Sin certificado' }}</strong><p class="muted">{{ $site->ssl_auto_renew ? 'Renovación automática activa' : 'Renovación pendiente de confirmar' }}</p></div>
            <div><strong>Contacto</strong><p class="muted">{{ Auth::user()->email }}</p></div>
            <div><strong>Última operación</strong><p class="muted">{{ $latestOperation ? ucfirst($latestOperation->status).' · '.$latestOperation->created_at->diffForHumans() : 'Aún no se ha solicitado' }}</p></div>
        </div>
    </section>

    <section class="panel">
        <h2>Componentes protegidos</h2>
        <div class="database-list panel"><div class="database-table-scroll"><table><thead><tr><th>Nombre</th><th>Cobertura</th><th>Estado</th></tr></thead><tbody>
            <tr><td>{{ $site->domain }}</td><td>Dominio principal</td><td>{{ $site->ssl_enabled ? 'Protegido' : 'Pendiente' }}</td></tr>
            <tr><td>www.{{ $site->domain }}</td><td>Alias www</td><td>Pendiente de incluir</td></tr>
            <tr><td>*.{{ $site->domain }}</td><td>Wildcard</td><td>{{ $dnsConfiguration?->hasCompleteConfiguration() ? 'Pendiente de servicio DNS y delegación' : 'Requiere configurar DNS del servidor' }}</td></tr>
        </tbody></table></div></div>
        <p class="muted">No se declara cobertura que no exista en el certificado actual. Wildcard requiere DNS-01, servicio DNS autoritativo y delegación verificada.</p>
    </section>

    <section class="panel">
        <h2>Emitir o reemitir certificado individual</h2>
        <p class="muted">Usará {{ Auth::user()->email }} como correo de contacto. El DNS del dominio debe apuntar a este VPS y HTTP debe estar accesible.</p>
        <form wire:submit="issueCertificate" class="stack"><label class="check"><input type="checkbox" wire:model="acceptCertificateTerms"> Acepto los términos de Let's Encrypt.</label><div><button class="primary" wire:loading.attr="disabled">{{ $site->ssl_enabled ? 'Reemitir certificado' : 'Solicitar certificado' }}</button></div></form>
        @error('acceptCertificateTerms')<p class="error">{{ $message }}</p>@enderror
        @error('certificate')<p class="error">{{ $message }}</p>@enderror
    </section>
</div>
