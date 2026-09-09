<x-layouts.app>

    <section class="hero">
        <div>
            <p class="eyebrow">SEGURIDAD DE CUENTA</p>
            <h1>Acceso seguro.</h1>
            <p class="muted">Usa passkeys, biometría y un autenticador TOTP para proteger el panel.</p>
        </div>
    </section>

    @if(session('notice'))
        <div class="notice">{{ session('notice') }}</div>
    @endif

    <section class="panel">
        <h2>Passkeys</h2>
        <p class="muted">Registra Touch ID, Face ID, Windows Hello o el gestor de contraseñas de un dispositivo. Podrás iniciar sesión sin escribir tu contraseña.</p>

        @if($canManagePasskeys)
            <div class="passkey-register">
                <label>Nombre de este dispositivo
                    <input data-passkey-name maxlength="80" placeholder="Ej. MacBook de Mauricio">
                </label>
                <button class="primary" type="button" data-passkey-register>Agregar passkey</button>
            </div>
            <p class="muted" data-passkey-register-status aria-live="polite"></p>
        @else
            <div class="notice">Confirma tu contraseña para agregar o revocar passkeys durante esta sesión.</div>
            <a class="primary" href="{{ route('password.confirm') }}">Confirmar contraseña</a>
        @endif

        <div class="passkey-list">
            @forelse($passkeys as $passkey)
                <div class="passkey-row">
                    <span class="mark">🔑</span>
                    <div>
                        <strong>{{ $passkey->name }}</strong>
                        <p class="muted">
                            {{ $passkey->authenticator ?: 'Dispositivo compatible con passkeys' }}
                            · añadido {{ $passkey->created_at->diffForHumans() }}
                            @if($passkey->last_used_at) · usado {{ $passkey->last_used_at->diffForHumans() }} @endif
                        </p>
                    </div>
                    @if($canManagePasskeys)
                        <form method="POST" action="{{ route('passkey.destroy', $passkey) }}" onsubmit="return confirm('¿Revocar esta passkey? El dispositivo dejará de poder entrar al panel.');">
                            @csrf
                            @method('DELETE')
                            <button class="danger-button" type="submit">Revocar</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="muted">Aún no tienes passkeys registradas.</p>
            @endforelse
        </div>
    </section>

    <section class="panel">
        @if(!auth()->user()->two_factor_confirmed_at && !$secret)
            <h2>2FA no está activo</h2>
            <p class="muted">Úsalo como respaldo. Al activarlo necesitarás tu contraseña y un código del autenticador para deshabilitarlo.</p>
            <form method="POST" action="{{ route('security.two-factor.begin') }}">@csrf<button class="primary">Configurar autenticador</button></form>
        @elseif($secret)
            <h2>Escanea y confirma</h2>
            <p class="muted">Escanea el QR con 1Password, Google Authenticator, Authy u otra app TOTP. Si no puedes escanearlo, usa esta clave: <code>{{ $secret }}</code></p>
            <div class="two-factor-qr" aria-label="Código QR para configurar 2FA">{!! $qrCode !!}</div>
            <form method="POST" action="{{ route('security.two-factor.confirm') }}" class="stack">
                @csrf
                <label>Código de 6 dígitos<input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
                @error('code')<p class="error">{{ $message }}</p>@enderror
                <button class="primary">Activar 2FA</button>
            </form>
        @elseif(auth()->user()->two_factor_confirmed_at)
            <h2>2FA está activo</h2>
            <p class="ok">Tu cuenta requiere un autenticador al entrar con contraseña.</p>
            <form method="POST" action="{{ route('security.two-factor.disable') }}" class="stack">
                @csrf @method('DELETE')
                <label>Contraseña actual<input name="password" type="password" required></label>
                <label>Código TOTP o de recuperación<input name="code" required></label>
                @error('code')<p class="error">{{ $message }}</p>@enderror
                <button class="danger-button">Desactivar 2FA</button>
            </form>
        @endif
    </section>

    @if($recoveryCodes)
        <section class="panel">
            <h2>Códigos de recuperación</h2>
            <p class="muted">Guárdalos fuera del servidor. Cada uno funciona una sola vez y no se mostrará otra vez.</p>
            <pre><code>{{ implode("\n", $recoveryCodes) }}</code></pre>
        </section>
    @endif

    <section class="panel">
        <h2>Actividad de seguridad</h2>
        @forelse($activity as $item)
            <div class="activity-row"><span class="dot finished"></span><div><strong>{{ str($item->event)->replace('_', ' ') }}</strong><p>{{ $item->ip_address ?: 'IP no disponible' }}</p></div><time>{{ $item->created_at->diffForHumans() }}</time></div>
        @empty
            <p class="muted">Aún no hay eventos.</p>
        @endforelse
    </section>
</x-layouts.app>
