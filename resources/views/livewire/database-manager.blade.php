<div class="database-page {{ $embedded ? 'database-page-embedded' : '' }}" x-data="databaseDownloads()" wire:init="refreshStatistics">
    <div class="section-title"><div><h1>Bases de datos · {{ $site->domain }}</h1><p>MariaDB local · Puerto configurado: {{ $settings?->db_port ?? 3306 }} · Host local: localhost</p></div>@unless($embedded)<a class="secondary" href="{{ route('dashboard') }}">Volver a dominios</a>@endunless</div>
    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif
    @if($errors->any() && ! $modal)<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="database-toolbar"><button class="primary" wire:click="newDatabase">Crear base</button><button class="secondary" wire:click="newUser">Crear usuario</button><button class="ghost" wire:click="apply" wire:loading.attr="disabled">Reintentar pendientes</button><button class="ghost" wire:click="refreshStatistics(true)" wire:loading.attr="disabled">Actualizar estado</button><span wire:loading>Procesando…</span></div>
    <section class="panel database-list">
        <div class="section-title"><h2>Bases de datos <small>{{ $databases->count() }}</small></h2><button class="ghost" wire:click="refreshStatistics(true)" wire:loading.attr="disabled">Actualizar estadísticas</button></div>
        <small>Tamaño estimado de datos + índices. Tablas físicas, sin contar vistas.</small>
        @if($statisticsError)<p role="alert" class="muted">{{ $statisticsError }}</p>@endif
        <div class="database-table-scroll"><table><thead><tr><th>Nombre</th><th>Collation</th><th>Tamaño</th><th>Tablas</th><th>Usuarios con acceso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
        @forelse($databases as $database)
            <tr wire:key="database-{{ $database->id }}"><td><code>{{ $database->name }}</code></td><td><code>{{ $database->collation }}</code></td><td>{{ isset($statistics[$database->name]) ? number_format($statistics[$database->name]['bytes'] / 1048576, 2).' MB' : '—' }}</td><td>{{ $statistics[$database->name]['tables'] ?? '—' }}</td><td>{{ $databaseUsers->filter(fn ($user) => $user->hasAccessTo($database))->pluck('name')->join(', ') ?: 'Sin usuarios asignados' }}</td><td><span class="database-status" data-status="{{ $database->status }}">{{ $database->status === 'active' ? 'Activa' : 'Pendiente' }}</span></td><td><details><summary aria-label="Acciones de {{ $database->name }}">•••</summary><div class="database-row-actions"><button wire:click="prepareOperation('export', {{ $database->id }})">Exportar dump ZIP</button><button wire:click="prepareOperation('import', {{ $database->id }})">Importar SQL</button><button wire:click="prepareOperation('delete-database', {{ $database->id }})">Eliminar base</button></div></details></td></tr>
        @empty<tr><td colspan="7" class="muted">Aún no hay bases de datos. Crea la primera con el botón de arriba.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel database-list">
        <h2>Usuarios <small>{{ $databaseUsers->count() }}</small></h2>
        <div class="database-table-scroll"><table><thead><tr><th>Usuario</th><th>Bases asignadas</th><th>Permisos</th><th>Conexiones</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
        @forelse($databaseUsers as $user)
            <tr wire:key="database-user-{{ $user->id }}"><td><code>{{ $user->name }}</code></td><td>{{ ! $user->site_database_id && $user->all_databases ? 'Todas · actuales y futuras' : ($databases->filter(fn ($database) => $user->hasAccessTo($database))->pluck('name')->join(', ') ?: 'Sin bases asignadas') }}</td><td>{{ ['read' => 'Solo lectura', 'write' => 'Lectura y escritura', 'admin' => 'Administración'][$user->permission] ?? $user->permission }}</td><td>{{ $user->access === 'local' ? 'Solo local' : ($user->access === 'any' ? 'Local + cualquier IP' : 'Local + '.$user->remote_ip) }}</td><td><span class="database-status" data-status="{{ $user->status }}">{{ $user->status === 'active' ? 'Activo' : 'Pendiente' }}</span></td><td><details><summary aria-label="Acciones de {{ $user->name }}">•••</summary><div class="database-row-actions"><button wire:click="editUser({{ $user->id }})">Editar permisos y acceso</button><button wire:click="prepareOperation('delete-user', {{ $user->id }})">Eliminar usuario</button></div></details></td></tr>
        @empty<tr><td colspan="6" class="muted">Aún no hay usuarios. Puedes asignarles una base o todas las de este dominio.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    @if($operations->isNotEmpty())
    <section class="database-operations" aria-live="polite"><h2>Operaciones recientes</h2>
        @foreach($operations as $operation)
            <div wire:key="db-operation-{{ $operation->id }}"><strong>{{ ['export' => 'Exportar ZIP', 'import' => 'Importar SQL', 'delete-database' => 'Eliminar base', 'delete-user' => 'Eliminar usuario'][$operation->action] }}</strong> · {{ $operation->resource_name }} · {{ ['pending' => 'En cola', 'running' => 'Procesando', 'finished' => 'Completada', 'downloaded' => 'Descarga enviada', 'failed' => 'Fallida'][$operation->status] }}
            @if($operation->status === 'running')<p>{{ $operation->message ?: 'Procesando en el servidor…' }}</p>@endif
            @if($operation->status === 'failed')<p role="alert">{{ $operation->message }}</p>@endif
            @if($operation->action === 'export' && $operation->status === 'finished')<span x-init="startDownload({{ $operation->id }}, @js(route('sites.databases.download', [$site, $operation])))"> · Iniciando descarga…</span><a href="{{ route('sites.databases.download', [$site, $operation]) }}">Si no inició, descargar</a>@endif</div>
        @endforeach
    </section>
    @endif
    <details class="database-help"><summary>Información de conexión y operaciones pendientes</summary><p>Usa los nombres completos en DB_DATABASE y DB_USERNAME y la contraseña que elegiste. El panel no modifica el .env. Las bases externas no se adoptan automáticamente. Las importaciones fallidas pueden quedar parcialmente aplicadas y no se reintentan automáticamente.</p></details>
    @if($modal)
    <dialog wire:ignore.self class="database-dialog" wire:key="database-dialog-{{ $modal }}" x-data="databaseTransfer($wire, @js(route('sites.database-uploads.start', $site)))" x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeModal()" aria-labelledby="database-dialog-title">
        <div class="section-title"><h2 id="database-dialog-title">{{ $modal === 'operation' ? (['export' => 'Exportar dump ZIP', 'import' => 'Importar SQL', 'delete-database' => 'Eliminar base', 'delete-user' => 'Eliminar usuario'][$operationAction] ?? 'Confirmar operación') : ($modal === 'database' ? 'Crear base de datos' : ($editingUserId ? 'Editar usuario' : 'Crear usuario')) }}</h2><button class="ghost" wire:click="closeModal" aria-label="Cerrar">×</button></div>
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if($modal === 'operation')
        <form wire:submit="confirmOperation" class="form-grid">
            <p><strong>{{ $operationName }}</strong></p>
            @if($operationAction === 'import')
                <label>Archivo SQL<input type="file" x-on:change="upload($event.target.files[0])" :disabled="uploading" accept=".sql" required></label>
                <div x-show="uploading || ready"><progress max="100" :value="progress"></progress> <span x-text="progress + '% cargado'"></span></div><p x-show="error" x-text="error" role="alert"></p>
                <p>Al confirmar se ejecutará este SQL en la base seleccionada. Puede reemplazar o eliminar datos existentes; no se crea un respaldo automático.</p>
                <small>Hasta 1 GB, carga por fragmentos y procesamiento en segundo plano con hasta 2 horas. Solo permisos sobre esta base. Dumps con DEFINER de otro usuario o instrucciones globales pueden fallar.</small>
            @elseif($operationAction === 'export')
                <p>La descarga del ZIP con el dump .sql iniciará automáticamente al terminar. El ZIP temporal se eliminará al finalizar el envío.</p>
            @elseif($operationAction === 'delete-database')
                <p>¿Eliminar esta base y todos sus datos definitivamente? Se retirarán sus permisos. Los usuarios se conservarán y las otras bases no se eliminarán.</p>
            @else
                <p>¿Eliminar este usuario y todos sus accesos locales y remotos? Las bases y sus datos se conservarán.</p>
            @endif
            <div class="database-toolbar"><button class="primary" wire:loading.attr="disabled" @if($operationAction === 'import') :disabled="uploading || !ready" @endif>{{ $operationAction === 'export' ? 'Exportar ZIP' : 'Confirmar' }}</button><button type="button" class="secondary" wire:click="closeModal">Cancelar</button><span wire:loading>Procesando…</span></div>
        </form>
        @elseif($modal === 'database')
        <form wire:submit="createDatabase" class="form-grid">
            <label>Nombre de la base<input wire:model="databaseName" placeholder="{{ $prefix }}app" maxlength="64" required><small>Prefijo sugerido (opcional): {{ $prefix }}. Se usará exactamente lo que escribas. Hasta 64 caracteres: letras, números y guion bajo; empieza con una letra.</small></label>
            <label>Collation<select wire:model="databaseCollation"><option value="utf8mb4_spanish2_ci">utf8mb4_spanish2_ci — Español moderno</option><option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci — Unicode general</option></select><small>Predeterminado para el panel: <code>utf8mb4_spanish2_ci</code>.</small></label>
            <label>Usuario de la base<select wire:model.live="databaseUserMode"><option value="none">Sin usuario por ahora</option><option value="new">Crear usuario nuevo</option><option value="existing">Seleccionar usuario existente</option></select></label>
            @if($databaseUserMode === 'new')
                <label>Nombre de usuario<input wire:model="username" maxlength="32" required autocomplete="off" placeholder="{{ $prefix }}user"></label>
                <label>Contraseña<input type="password" wire:model="password" autocomplete="new-password" required><small>Mínimo 12 caracteres.</small></label>
                <label>Privilegios<select wire:model="permission"><option value="read">Solo lectura</option><option value="write">Lectura y escritura</option><option value="admin">Administración de esta base</option></select></label>
                <label>Conexiones<select wire:model.live="access"><option value="local">Solo local</option><option value="ip">Local y una IP remota</option><option value="any">Local y cualquier IP remota</option></select></label>
                @if($access === 'ip')<label>IP autorizada<input wire:model="remoteIp" placeholder="203.0.113.10"></label>@endif
                @if($access !== 'local')<label class="check"><input type="checkbox" wire:model="confirmRemote"> Autorizo este acceso remoto; requerirá TLS.</label><small>No abre el firewall ni modifica la interfaz de escucha.</small>@endif
            @elseif($databaseUserMode === 'existing')
                <label>Usuario existente<select wire:model="existingDatabaseUserId" required><option value="">Selecciona un usuario</option>@foreach($databaseUsers as $user)<option wire:key="create-db-user-{{ $user->id }}" value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
                <small>Se añadirá esta base conservando sus accesos, privilegios, contraseña y conexiones actuales.</small>
            @endif
            <small>Los usuarios con acceso a todas las bases del dominio tendrán acceso automáticamente.</small>
            <div class="database-toolbar"><button class="primary" wire:loading.attr="disabled">{{ $databaseUserMode === 'none' ? 'Crear base' : 'Crear base y asignar usuario' }}</button><button type="button" class="secondary" wire:click="closeModal">Cancelar</button></div>
        </form>
        @else
        <form wire:submit="saveUser" class="form-grid">
            <label>Nombre de usuario<input wire:model="username" placeholder="{{ $prefix }}user" maxlength="32" @readonly($editingUserId) required><small>Prefijo sugerido (opcional): {{ $prefix }}. Sin prefijo automático. Hasta 32 caracteres: letras, números y guion bajo; empieza con una letra.</small></label>
            <label>Contraseña<input type="password" wire:model="password" autocomplete="new-password"><small>Mínimo 12 caracteres. Al editar, déjala vacía para conservarla.</small></label>
            <label>Acceso a bases<select wire:model="databaseId"><option value="">Todas las bases del dominio, actuales y futuras</option><option value="0">Sin bases asignadas</option>@foreach($databases as $database)<option wire:key="db-option-{{ $database->id }}" value="{{ $database->id }}">{{ $database->name }}</option>@endforeach</select></label>
            @if($editingUserId)<small>Si cambias esta selección se reemplazarán las asignaciones anteriores. Sin cambiarla, se conservan las bases añadidas al crear otras bases.</small>@endif
            <label>Privilegios<select wire:model="permission"><option value="read">Solo lectura</option><option value="write">Lectura y escritura</option><option value="admin">Administración de esas bases</option></select></label>
            <label>Conexiones<select wire:model.live="access"><option value="local">Solo local</option><option value="ip">Local y una IP remota</option><option value="any">Local y cualquier IP remota</option></select></label>
            @if($access === 'ip')<label>IP autorizada<input wire:model="remoteIp" placeholder="203.0.113.10"></label>@endif
            @if($access !== 'local')<label class="check"><input type="checkbox" wire:model="confirmRemote"> Autorizo este acceso remoto; requerirá TLS.</label><p class="muted">Esto configura permisos en MariaDB. No abre el firewall ni cambia la interfaz de escucha. Para DataGrip deben estar preparados TLS, el puerto del servidor y las reglas del proveedor.</p>@endif
            <div class="database-toolbar"><button class="primary" wire:loading.attr="disabled">{{ $editingUserId ? 'Guardar usuario' : 'Crear usuario' }}</button><button type="button" class="secondary" wire:click="closeModal">Cancelar</button><span wire:loading>Aplicando…</span></div>
        </form>
        @endif
    </dialog>
    @endif
</div>
