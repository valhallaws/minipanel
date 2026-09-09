<h3>Archivos y respaldos</h3>
<div class="domain-tool-grid">
    <button type="button" @click="tab = 'databases'; $wire.openDatabases()"><i><x-ui-icon name="database" /></i><span>Bases de datos<small>Bases, usuarios y permisos</small></span></button>
    <a href="{{ route('sites.files', $site) }}"><i><x-ui-icon name="folder" /></i><span>Archivos<small>Explorador del dominio</small></span></a>
    <a href="{{ route('sites.backups', $site) }}"><i><x-ui-icon name="backup" /></i><span>Backup y restauración<small>Copias del dominio</small></span></a>
</div>
<h3>Herramientas de desarrollo</h3>
<div class="domain-tool-grid">
    <button @click="drawer = 'hosting'"><i><x-ui-icon name="code" /></i><span>Hosting<small>PHP y carpeta pública</small></span></button>
    <button @click="drawer = 'logs'"><i><x-ui-icon name="file" /></i><span>Logs<small>Aplicación y servidor web</small></span></button>
    <a href="{{ route('sites.git', $site) }}"><i><x-ui-icon name="git" /></i><span>Git<small>Repositorios y claves SSH</small></span></a>
    @if($site->runtime === 'laravel' || $site->has_laravel_repository)<button type="button" @click="tab = 'laravel'; $wire.openLaravel()"><i><x-ui-icon name="code" /></i><span>Laravel<small>Entorno, mantenimiento y herramientas</small></span></button>@endif
</div>
<h3>Hosting y seguridad</h3>
<div class="domain-tool-grid">
    <a href="{{ route('sites.ssl', $site) }}"><i><x-ui-icon name="shield" /></i><span>SSL/TLS<small>{{ $site->ssl_enabled ? 'Certificado emitido' : 'Solicitar certificado' }}</small></span></a>
    <button @click="drawer = 'hosting'"><i><x-ui-icon name="settings" /></i><span>Nginx<small>Límites y directivas</small></span></button>
    <button @click="drawer = 'health'"><i><x-ui-icon name="network" /></i><span>DNS y salud</span></button>
</div>
