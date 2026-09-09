<div>
    <section class="hero">
        <div>
            <p class="eyebrow">ADMINISTRACIÓN AVANZADA</p>
            <h1>Consola root del VPS</h1>
            <p class="muted">Sesión root real del VPS. Todo lo que ejecutes aquí afecta directamente al servidor.</p>
        </div>
        <a class="ghost" href="{{ route('server.setup') }}">← Servidor</a>
    </section>

    @if($passwordConfirmed)
        <section class="panel terminal-panel">
            <div class="section-title"><div><h2>root@vps</h2><p>La conexión vive sólo dentro del panel autenticado; no se expone ningún puerto público adicional.</p></div></div>
            <iframe class="server-terminal" src="/server-terminal/" title="Consola root del VPS" allow="clipboard-read; clipboard-write"></iframe>
        </section>
    @else
        <section class="panel danger-zone">
            <h2>Confirma tu contraseña para abrir root</h2>
            <p class="muted">La confirmación protege esta sesión incluso si el navegador queda desbloqueado. Dura {{ intdiv((int) config('auth.password_timeout', 10800), 60) }} minutos.</p>
            <a class="danger-button" href="{{ route('password.confirm', ['intended' => route('server.terminal')]) }}">Confirmar y abrir consola root</a>
        </section>
    @endif
</div>
