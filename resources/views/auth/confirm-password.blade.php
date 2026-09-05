<x-layouts.app>
    <section class="login-card">
        <div class="mark">MP</div>
        <p class="eyebrow">CONFIRMACIÓN DE SEGURIDAD</p>
        <h1>Confirma tu contraseña.</h1>
        <p class="muted">Se requiere antes de agregar o revocar una passkey.</p>
        <form method="POST" action="{{ route('password.confirm.store') }}" class="stack">
            @csrf
            <label>Contraseña actual<input name="password" type="password" autocomplete="current-password" required autofocus></label>
            @error('password')<p class="error">{{ $message }}</p>@enderror
            <button class="primary" type="submit">Confirmar</button>
            <a class="ghost" href="{{ route('security.settings') }}">Cancelar</a>
        </form>
    </section>
</x-layouts.app>
