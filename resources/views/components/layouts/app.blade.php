<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MiniPanel</title>
    <meta name="theme-color" content="#1d5c42"><link rel="manifest" href="/manifest.webmanifest"><link rel="apple-touch-icon" href="/icons/minipanel.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <main class="shell">{{ $slot }}</main>
    @livewireScripts
</body>
</html>
