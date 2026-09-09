<x-layouts.app>
    <section class="login-card">
        <div class="login-brand">
            <img class="freyja-login-mark" src="/brand/freyja-isotipo.png" alt="Freyja">
            <p class="eyebrow">CONTROL DEL VPS</p>
            <h1>Freyja</h1>
            <p>Control de tu servidor, sin depender de un dominio.</p>
        </div>
        <form method="POST" action="/login" class="stack login-form">@csrf
            <label>Usuario o correo<input name="login" type="text" value="{{ old('login', old('email')) }}" autocomplete="username webauthn" autocapitalize="none" spellcheck="false" required autofocus></label>
            <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
            @error('login')<p class="error">{{ $message }}</p>@enderror
            @error('password')<p class="error">{{ $message }}</p>@enderror
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
