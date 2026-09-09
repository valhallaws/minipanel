<div class="git-page {{ $embedded ? 'git-page-embedded' : '' }}" @if($repositories->whereIn('status', ['queued', 'running'])->isNotEmpty()) wire:poll.2s @endif>
    @unless($embedded)
    <nav class="git-breadcrumb"><a href="{{ route('dashboard') }}">Sitios web y dominios</a><span>›</span><span>{{ $site->domain }}</span><span>›</span><span>Git</span></nav>
    @endunless
    <div class="section-title"><div><h1>Repositorios Git · {{ $site->domain }}</h1><p>SSH · Destinos independientes · Raíz web: {{ $site->document_root ?? '.' }}</p></div><button class="primary" wire:click="openCreate" wire:loading.attr="disabled">Añadir repositorio</button></div>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <span wire:loading wire:target="openCreate,browse,createFolder,createRepository,syncRepository">Procesando…</span>
    <section class="panel"><div class="section-title"><div><h2>Script post-deploy</h2><p>Un comando por línea. En Laravel, Freyja activa mantenimiento antes del pull, instala Composer y Node cuando la preparación está habilitada, ejecuta este script y solo entonces vuelve a levantar la aplicación. Si algo falla, se conserva el mantenimiento.</p></div><button class="primary" wire:click="saveSiteDeployCommands">Guardar script</button></div><textarea wire:model="siteDeployCommands" class="env-editor" rows="8" spellcheck="false" placeholder="composer install --no-interaction --no-dev&#10;npm ci&#10;npm run build&#10;php artisan migrate --force&#10;php artisan optimize:clear&#10;php artisan optimize"></textarea>@error('siteDeployCommands')<p class="error">{{ $message }}</p>@enderror</section>
    @if($site->repository)<div class="notice">Este dominio tiene una configuración Git anterior. No se ha movido ni adoptado ese repositorio automáticamente. Sus archivos se conservan.</div>@endif
    <div class="git-grid">
        @foreach($repositories as $repository)
        <article class="git-card" wire:key="repository-{{ $repository->id }}">
            <header><x-ui-icon name="git" /><h2>{{ $repository->name }}</h2><span class="git-status">{{ ['configured' => 'Configurado', 'queued' => 'Pendiente', 'running' => 'Actualizando', 'ready' => 'Listo', 'failed' => 'Requiere atención'][$repository->status] ?? $repository->status }}</span></header>
            <dl><dt>URL SSH</dt><dd><code>{{ $repository->url }}</code></dd><dt>Carpeta</dt><dd>/{{ $repository->directory }}</dd><dt>Rama</dt><dd>
                @if($repository->branches)
                <select wire:model="selectedBranches.{{ $repository->id }}" aria-label="Rama de {{ $repository->name }}" @disabled(in_array($repository->status, ['queued', 'running']))>
                    <option value="{{ $repository->branch }}">{{ $repository->branch }} · actual</option>
                    @foreach($repository->branches as $branch) @if($branch !== $repository->branch)<option wire:key="branch-{{ $repository->id }}-{{ $branch }}" value="{{ $branch }}">{{ $branch }}</option>@endif @endforeach
                </select>
                @else Predeterminada del remoto · se detecta al clonar @endif
            </dd><dt>Proyecto</dt><dd>{{ $repository->project_type ?? 'Pendiente de detección' }}</dd></dl>
            <details><summary>Clave pública SSH</summary><textarea readonly aria-label="Clave pública" rows="4">{{ $repository->public_key }}</textarea><button class="ghost" type="button" x-data @click="navigator.clipboard.writeText(@js($repository->public_key))">Copiar clave</button></details>
            <section><h3>Últimos commits</h3>@forelse($repository->commits ?? [] as $commit)<p wire:key="commit-{{ $repository->id }}-{{ $commit['hash'] }}"><code>{{ substr($commit['hash'], 0, 8) }}</code> {{ $commit['subject'] }}</p>@empty<p class="muted">Aparecerán después de la primera clonación.</p>@endforelse</section>
            <p class="muted">{{ $repository->prepare_project ? 'Preparación automática según el proyecto: habilitada.' : 'Solo actualizar archivos; sin instalar dependencias.' }}</p>
            <details><summary>Configuración de actualización</summary>
                <label class="check"><input type="checkbox" @checked($repository->prepare_project) wire:change="setPreparation({{ $repository->id }}, $event.target.checked)" @disabled(in_array($repository->status, ['queued', 'running']))> Preparar dependencias y assets al actualizar</label>
                <label class="check"><input type="checkbox" @checked($repository->automatic) wire:change="setAutomatic({{ $repository->id }}, $event.target.checked)"> Pull automático mediante webhook</label>
                @if($repository->automatic)<p>Configura un webhook de push (JSON) en GitHub o GitLab. Solo actualiza la rama seleccionada; no se registra automáticamente en tu proveedor.</p><label>URL<input readonly value="{{ route('webhooks.repository', $repository->key_token) }}"></label><label>Secreto<input readonly type="password" value="{{ $repository->webhook_secret }}"></label><button class="ghost" type="button" x-data @click="navigator.clipboard.writeText(@js($repository->webhook_secret))">Copiar secreto</button>@endif
                @if($repository->project_type === 'Laravel')<p>Laravel detectado. Para servirlo configura <code>{{ $repository->directory }}/public</code> en <a href="{{ route('sites.show', $site) }}">Hosting</a>. La raíz web no cambia automáticamente.</p>@endif
            </details>
            @if($repository->error)<p class="error" role="alert">{{ $repository->error }}</p>@endif
            <footer><button class="primary" wire:click="syncRepository({{ $repository->id }})" wire:loading.attr="disabled" @disabled(in_array($repository->status, ['queued', 'running']))>{{ $repository->status === 'failed' ? 'Reintentar' : 'Pull ahora' }}</button><button class="secondary" wire:click="showProgress({{ $repository->id }})">Ver progreso</button><small>{{ $repository->synced_at?->format('d/m/Y H:i') }}</small></footer>
        </article>
        @endforeach
        <button class="git-add" wire:click="openCreate" wire:loading.attr="disabled"><x-ui-icon name="git" /><span>＋ Añadir repositorio</span><small>Clonar por SSH en una carpeta de este dominio</small></button>
    </div>
    @if($showCreate)
    <dialog class="git-dialog" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.closeCreate()" aria-label="Crear repositorio">
        <header><h2>Crear repositorio</h2><button class="ghost" wire:click="closeCreate" aria-label="Cerrar">×</button></header>
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if($showFolders)
            <h3>Seleccionar carpeta del dominio</h3><p>/{{ $folderPath === '.' ? '' : $folderPath }}</p>
            <div class="git-folders"><button class="ghost" wire:click="browse('.')"><x-ui-icon name="folder" /> Carpeta del dominio</button>
            @if($folderPath !== '.')<button class="ghost" wire:click="browse(@js(dirname($folderPath)))">↑ Subir un nivel</button>@endif
            @forelse($folders as $folder)<button class="ghost" wire:key="folder-{{ $folder['path'] }}" wire:click="browse(@js($folder['path']))"><x-ui-icon name="folder" />{{ $folder['name'] }}</button>@empty<p class="muted">No hay subcarpetas.</p>@endforelse</div>
            <form wire:submit="createFolder" class="git-new-folder"><label>Nueva carpeta<input wire:model="folderName" maxlength="101" required></label><button class="secondary" wire:loading.attr="disabled">Crear y seleccionar</button></form>
            <footer><button class="primary" wire:click="selectFolder" @disabled($folderPath === '.')>Usar esta carpeta</button><button class="secondary" wire:click="$set('showFolders', false)">Volver</button></footer>
        @else
            <form wire:submit="createRepository" class="git-form">
                <label>URL del repositorio SSH<input wire:model="url" placeholder="git@github.com:equipo/proyecto.git" required autocomplete="off"></label>
                <section><h3>Clave pública de despliegue</h3><p>Agrega esta clave al proveedor Git con permiso de lectura antes de guardar. La clave privada permanece en el servidor.</p><textarea readonly rows="4" aria-label="Clave pública SSH">{{ $draft?->public_key }}</textarea><button type="button" class="secondary" x-data @click="navigator.clipboard.writeText(@js($draft?->public_key))">Copiar clave</button></section>
                <label>Nombre del repositorio<input wire:model="name" maxlength="80" required></label>
                <label>Carpeta destino<div class="git-path"><input wire:model="directory" required><button type="button" class="secondary" wire:click="browse('.')"><x-ui-icon name="folder" /> Explorar</button></div></label>
                <small>Debe estar vacía. No se sobrescriben archivos existentes ni se modifica la raíz web. Se clonará la rama predeterminada del remoto.</small>
                <label class="check"><input type="checkbox" wire:model="prepareProject"> Preparar el proyecto después de clonar y en los siguientes pulls</label>
                <small>Laravel: Composer sin dependencias de desarrollo, .env y clave solo si faltan. Vite/Mix: dependencias y compilación. Estos pasos ejecutan código del repositorio con el usuario del dominio: usa repositorios de confianza. No se ejecutan migraciones ni seeders.</small>
                <footer><button class="primary" wire:loading.attr="disabled">Guardar y clonar</button><button type="button" class="secondary" wire:click="closeCreate">Cancelar</button></footer>
            </form>
        @endif
    </dialog>
    @endif
    @if($progress)
    <dialog class="git-dialog git-progress" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.minimizeProgress()" aria-label="Progreso del repositorio">
        <header><h2>{{ $progress->name }}</h2><button class="ghost" wire:click="minimizeProgress">Minimizar</button></header>
        <div aria-live="polite">@if($progress->status === 'queued')<p>Esperando al ejecutor del servidor…</p>@endif
        @foreach($progress->steps ?? [] as $step)<section wire:key="step-{{ $loop->index }}" class="git-progress-step git-progress-{{ $step['status'] }}"><strong>{{ $step['label'] }}</strong><span> · {{ ['pending' => 'Pendiente', 'running' => 'Ejecutándose', 'done' => 'Completado', 'failed' => 'Falló'][$step['status']] ?? $step['status'] }}</span>@if($step['status'] === 'running')<progress aria-label="Operación en curso"></progress>@endif @if($step['status'] === 'failed' && $step['detail'])<pre>{{ $step['detail'] }}</pre>@endif</section>@endforeach
        @if($progress->error)<p class="error">El despliegue falló.</p><pre class="git-progress-error">{{ $progress->error }}</pre><button class="primary" wire:click="syncRepository({{ $progress->id }})">Reintentar</button>@endif</div>
    </dialog>
    @endif
</div>
