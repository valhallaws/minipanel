<x-layouts.app>
    <section class="login-card">
        <div class="mark">MP</div><p class="eyebrow">CONTROL DEL SERVIDOR</p>
        <h1>MiniPanel</h1><p class="muted">Entra para administrar tus aplicaciones.</p>
        <form method="POST" action="/login" class="stack">@csrf
            <label>Correo<input name="email" type="email" value="{{ old('email') }}" autocomplete="email webauthn" required autofocus></label>
            <label>Contraseña<input name="password" type="password" required></label>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            <label class="check"><input name="remember" type="checkbox"> Mantener sesión</label>
            <button class="primary" type="submit">Entrar al panel</button>
        </form>
        <div class="passkey-login">
            <span>o</span>
            <button class="secondary" type="button" data-passkey-login>Entrar con passkey</button>
            <p class="muted" data-passkey-status aria-live="polite"></p>
        </div>
    </section>
</x-layouts.app>
