<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Freyja · Control del VPS</title>
    <script>
        window.applyPanelTheme = function (mode) {
            document.documentElement.dataset.themeMode = mode;
            document.documentElement.dataset.theme = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        };
        try { window.applyPanelTheme(JSON.parse(localStorage.getItem('minipanel.theme')) || 'system'); } catch { window.applyPanelTheme('system'); }
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => window.applyPanelTheme(document.documentElement.dataset.themeMode));
    </script>
    <meta name="theme-color" content="#287cca">
    <link rel="icon" href="/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicons/favicon-96x96.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ theme: $persist('system').as('minipanel.theme'), sidebarOpen: false }" x-effect="window.applyPanelTheme(theme)">
    @auth
    <div class="app-frame">
        <aside class="app-sidebar" :class="{ 'is-open': sidebarOpen }">
            <a class="hosting-logo freyja-sidebar-logo" href="{{ route('dashboard') }}"><img src="/brand/freyja-logo.png" alt="Freyja"></a>
            <nav aria-label="Navegación principal">
                <a href="{{ route('dashboard') }}" @class(['selected' => request()->routeIs('dashboard', 'sites.*')])><x-ui-icon name="globe" />Sitios web y dominios</a>
                <a href="{{ route('tools.dns') }}" @class(['selected' => request()->routeIs('tools.dns')])><x-ui-icon name="network" />DNS</a>
                <a href="{{ route('server.health') }}" @class(['selected' => request()->routeIs('server.health')])><x-ui-icon name="activity" />Monitorización</a>
                <a href="{{ route('server.setup') }}" @class(['selected' => request()->routeIs('server.setup')])><x-ui-icon name="settings" />Servidor</a>
                <a href="{{ route('server.terminal') }}" @class(['selected' => request()->routeIs('server.terminal')])><x-ui-icon name="terminal" />Consola root</a>
                <a href="{{ route('security.settings') }}" @class(['selected' => request()->routeIs('security.*')])><x-ui-icon name="shield" />Seguridad y acceso</a>
            </nav>
        </aside>
        <button class="sidebar-scrim" x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" aria-label="Cerrar navegación"></button>
        <header class="app-topbar">
            <button class="sidebar-toggle ghost" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen" aria-label="Mostrar navegación"><x-ui-icon name="menu" /></button>
            <span>Administración del servidor</span>
            @if($panelCommit)<code class="app-version">{{ $panelCommit }}</code>@endif
            <span class="app-account">{{ auth()->user()->name }}</span>
            <label class="theme-choice"><span>Tema</span><select x-model="theme" aria-label="Tema"><option value="system">Sistema</option><option value="light">Claro</option><option value="dark">Oscuro</option></select></label>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="ghost">Salir</button></form>
        </header>
        <main class="shell app-content">{{ $slot }}</main>
    </div>
    @else
        <main class="shell">{{ $slot }}</main>
    @endauth
    @livewireScripts
</body>
</html>
