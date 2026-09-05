<div>
    <header class="topbar"><a class="brand" href="/"><span class="mark">MP</span> MiniPanel</a><a class="ghost" href="/">← Sitios</a></header>
    <section class="hero"><div><p class="eyebrow">CONFIGURACIÓN INICIAL DEL SERVIDOR</p><h1>Panel y MariaDB</h1><p class="muted">Guarda la configuración del host y del usuario provisionador. La contraseña se cifra y nunca vuelve a mostrarse.</p></div></section>
    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif
    <form wire:submit="save" class="panel form-grid">
        <h2>MiniPanel</h2>
        <label>Dominio del panel <small>(opcional)</small><input wire:model="panelDomain" placeholder="panel.ejemplo.com"></label>
        <label>Ruta del panel<input wire:model="panelPath" placeholder="/var/www/minipanel"></label>
        <label>Usuario del panel<input wire:model="panelUser" placeholder="www-data"></label>
        <label>PHP del panel<select wire:model="panelPhpVersion"><option>8.2</option><option>8.3</option><option>8.4</option></select></label>
        <label>IP pública del VPS <small>(para SSL automático)</small><input wire:model="publicIp" placeholder="65.99.225.120"></label>
        <h2>MariaDB provisionador</h2>
        <label>Host<input wire:model="host" placeholder="127.0.0.1"></label>
        <label>Puerto<input wire:model="port" type="number"></label>
        <label>Usuario DBA<input wire:model="username" autocomplete="off"></label>
        <label>Contraseña DBA<input wire:model="password" type="password" autocomplete="new-password"></label>
        <div class="form-actions"><button class="primary">Guardar configuración</button></div>
        @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    </form>
    <section class="panel"><h2>Instalación en Ubuntu</h2><p class="muted">Desde el directorio del MiniPanel en el VPS, primero revisa el plan y luego ejecútalo explícitamente.</p><pre><code>sudo ./ops-agent/install-minipanel --app-path {{ $panelPath }} --app-user {{ $panelUser }} --php-version {{ $panelPhpVersion }}@if($panelDomain) --panel-domain {{ $panelDomain }}@endif --with-firewall
sudo ./ops-agent/install-minipanel --apply --app-path {{ $panelPath }} --app-user {{ $panelUser }} --php-version {{ $panelPhpVersion }}@if($panelDomain) --panel-domain {{ $panelDomain }}@endif --with-firewall
php artisan minipanel:doctor</code></pre></section>
</div>
