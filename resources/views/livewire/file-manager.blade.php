<div class="explorer-page explorer-menus {{ $embedded ? 'explorer-page-embedded' : '' }}" x-data="{ query: '', mobilePanel: 'files', activityOpen: false }">
    @unless($embedded)
    <header class="topbar">
        <a class="brand" href="/"><img class="freyja-brand-mark" src="/brand/freyja-isotipo.png" alt="">Freyja</a>
        <div class="server">{{ $site->domain }} · Archivos</div>
        <a class="ghost" href="{{ route('sites.show', $site) }}">← Sitio</a>
    </header>
    @endunless

    <section class="file-hero">
        <div><p class="eyebrow">FILE MANAGER</p>@if($embedded)<h2>Archivos de {{ $site->domain }}</h2>@else<h1>{{ $site->name }}</h1>@endif<p class="muted">Explorador seguro de <code>{{ $site->path }}</code></p></div>
        <button wire:click="listDirectory" class="secondary" wire:loading.attr="disabled">↻ Actualizar</button>
    </section>

    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif

    <section class="file-explorer" @if($editing) style="grid-template-columns: minmax(0,1fr)" @endif>
        @if(!$editing)<aside class="file-sidebar" :class="{ 'mobile-open': mobilePanel === 'tree' }">
            <p class="file-sidebar-title">UBICACIONES</p>
            <button @if($editing) wire:confirm="¿Volver a la carpeta? Los cambios sin guardar se perderán." @endif wire:click="openDirectory('.')" x-on:click="query = ''; mobilePanel = 'files'" class="file-location {{ $directory === '.' ? 'active' : '' }}"><span>⌂</span> Raíz del sitio</button>
            @if($directory !== '.')<button wire:click="parentDirectory" class="file-location"><span>↑</span> Carpeta superior</button>@endif
            <nav class="tree-scroll" aria-label="Árbol de carpetas">@include('livewire.file-tree', ['treePath' => '.'])</nav>
        </aside>@endif

        <main class="file-workspace" :class="{ 'mobile-hidden': mobilePanel !== 'files' }">
            @if(!$editing)
            <div class="explorer-actionbar" aria-label="Acciones de archivos">
                <div class="explorer-menu" x-data="{ open: false }" x-on:click.outside="open = false"><button type="button" class="primary" x-on:click="open = !open" :aria-expanded="open">＋ Nuevo</button><div class="explorer-dropdown" x-show="open" x-cloak>
                    @foreach(['create'=>'Crear archivo','folder'=>'Crear carpeta','upload'=>'Subir archivo'] as $action=>$label)
                    <button wire:click="showModal('{{ $action }}')" x-on:click="open = false">{{ $label }}</button>
                    @endforeach
                </div></div>
                @foreach(['copy'=>'Copiar','move'=>'Mover','compress'=>'Comprimir','rename'=>'Renombrar','permissions'=>'Permisos','download'=>'Descargar','delete'=>'Eliminar'] as $action=>$label)
                    <button wire:key="action-{{ $action }}" wire:click="showModal('{{ $action }}')" class="secondary" @disabled(!$selectedPath)>{{ $label }}</button>
                @endforeach
                <button wire:click="showModal('extract')" class="secondary" @disabled(!str_ends_with(strtolower($selectedPath), '.zip'))>Descomprimir</button>
            </div>
            @endif
            <div class="file-toolbar">
                <button class="ghost explorer-mobile-toggle" x-on:click="mobilePanel = 'tree'">☰ Carpetas</button>
                <div class="file-breadcrumbs">
                    <button wire:click="openDirectory('.')" class="file-crumb">{{ $site->domain }}</button>
                    @if($directory !== '.')
                        @foreach(explode('/', $directory) as $index => $part)
                            @php
                                $breadcrumbPath = implode('/', array_slice(explode('/', $directory), 0, $index + 1));
                            @endphp
                            <span>/</span>
                            <button wire:key="crumb-{{ $breadcrumbPath }}" wire:click="openDirectory('{{ $breadcrumbPath }}')" class="file-crumb">{{ $part }}</button>
                        @endforeach
                    @endif
                </div>
                @if(!$editing)<div class="file-view-buttons" aria-label="Vista"><button wire:click="setViewMode('list')" class="{{ $viewMode === 'list' ? 'active' : '' }}" title="Vista de lista">☷</button><button wire:click="setViewMode('grid')" class="{{ $viewMode === 'grid' ? 'active' : '' }}" title="Vista de cuadrícula">▦</button></div>@endif
            </div>
            <p class="muted" wire:loading wire:target="openDirectory,parentDirectory,listDirectory">Abriendo carpeta…</p>
            @error('directory')<p class="error" role="alert">{{ $message }}</p>@enderror
            @error('file')<p class="error" role="alert">{{ $message }}</p>@enderror
            @if($editing)
                <div class="editor-tab">{{ $selectedPath }} <span wire:dirty wire:target="contents">· Sin guardar</span></div>
                <textarea wire:model="contents" class="file-editor full-editor" aria-label="Contenido del archivo" spellcheck="false"></textarea>
                <div class="editor-buttons"><button wire:click="saveFile" class="primary" wire:loading.attr="disabled">Guardar</button><button wire:click="closeEditor" wire:confirm="¿Cerrar el editor? Los cambios sin guardar se perderán." class="secondary">Volver a archivos</button></div>
            @else
            <div class="file-search"><span>⌕</span><input x-model="query" placeholder="Filtrar esta carpeta…"><small>{{ count($entries) }} elemento(s)</small></div>
            <div wire:key="directory-{{ $directory }}" class="file-browser {{ $viewMode === 'grid' ? 'is-grid' : '' }}" aria-live="polite">
                <div class="explorer-columns">
                    <button wire:click="sortBy('name')">Nombre {{ $sortColumn === 'name' ? ($sortDescending ? '↓' : '↑') : '' }}</button>
                    <button wire:click="sortBy('modified_at')">Modificado {{ $sortColumn === 'modified_at' ? ($sortDescending ? '↓' : '↑') : '' }}</button>
                    <button wire:click="sortBy('size')">Tamaño {{ $sortColumn === 'size' ? ($sortDescending ? '↓' : '↑') : '' }}</button>
                </div>
                @forelse($entries as $entry)
                    <div class="explorer-file-row" wire:key="row-{{ $entry['path'] }}" data-name="{{ strtolower($entry['name']) }}" x-show="!query || $el.dataset.name.includes(query.toLowerCase())">
                        <input type="radio" name="file-selection" aria-label="Seleccionar {{ $entry['name'] }}" wire:click="selectFile('{{ $entry['path'] }}')" @checked($selectedPath === $entry['path'])>
                    <button wire:key="file-{{ $entry['path'] }}" data-name="{{ strtolower($entry['name']) }}" x-show="!query || $el.dataset.name.includes(query.toLowerCase())" x-on:click="query = ''" wire:click="{{ $entry['type'] === 'directory' ? 'openDirectory' : 'openFile' }}('{{ $entry['path'] }}')" class="file-entry {{ $selectedPath === $entry['path'] ? 'selected' : '' }} {{ $entry['type'] }}">
                        <span class="file-entry-icon">{{ $entry['type'] === 'directory' ? '📁' : '📄' }}</span>
                        <span class="file-entry-name">{{ $entry['name'] }}</span>
                        <span class="file-entry-date">{{ $entry['modified_at'] ? date('Y-m-d H:i', $entry['modified_at']) : '—' }}</span>
                        <span class="file-entry-meta">{{ $entry['type'] === 'directory' ? 'Carpeta' : number_format($entry['size'] / 1024, 1).' KB' }}</span>
                    </button></div>
                @empty
                    <div class="file-empty"><span>🗂️</span><strong>{{ $errors->has('directory') ? 'Carpeta no disponible' : 'Carpeta vacía' }}</strong></div>
                @endforelse
            </div>
            @endif
        </main>


    </section>

    <section class="file-operation-drawer" @if(count($pendingOperations)) wire:poll.1500ms="refresh" @endif>
        <button class="ghost" x-on:click="activityOpen = !activityOpen" :aria-expanded="activityOpen">Actividad de archivos · {{ count($operations) }} <span x-text="activityOpen ? '▾' : '▸'"></span></button>
        <button class="ghost explorer-mobile-toggle" x-show="mobilePanel !== 'files'" x-on:click="mobilePanel = 'files'">← Volver a archivos</button>
        <div class="file-operation-list" x-show="activityOpen" x-cloak>@forelse($operations as $operation)<p wire:key="operation-{{ $operation->id }}"><span class="dot {{ $operation->status }}"></span><strong>{{ str($operation->action)->replace('file-', '') }}</strong> {{ $operation->path }} <small>{{ $operation->status }}</small></p>@empty<p class="muted">Sin operaciones todavía.</p>@endforelse</div>
    </section>

    @if($modal)
    <div class="explorer-modal-backdrop" x-data="{ uploading: false, progress: 0, uploadError: false }" x-init="$nextTick(() => $el.querySelector('input,button')?.focus())" x-on:keydown.escape="if (!uploading) $wire.set('modal', '')"
        x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false; uploadError = true"
        x-on:livewire-upload-cancel="uploading = false">
        <section class="explorer-modal" role="dialog" aria-modal="true" aria-labelledby="file-dialog-title">
            <h2 id="file-dialog-title">{{ ['create'=>'Crear archivo','folder'=>'Crear carpeta','upload'=>'Subir archivo','move'=>'Mover','copy'=>'Copiar','rename'=>'Renombrar','delete'=>'Eliminar','compress'=>'Comprimir','extract'=>'Descomprimir ZIP','permissions'=>'Cambiar permisos','download'=>'Descargar'][$modal] }}</h2>
            <p class="muted">{{ in_array($modal, ['create','folder','upload']) ? $directory : $selectedPath }}</p>
            <form wire:submit="submitModal" class="stack">
                @if(in_array($modal, ['create','rename','compress']))<label>Nombre<input wire:model="newName" required></label>@endif
                @if($modal === 'folder')<label>Nombre de carpeta<input wire:model="newFolder" required></label>@endif
                @if($modal === 'upload')
                    <label>Archivo<input type="file" wire:model="upload" required></label>
                    <p class="muted">Máximo {{ number_format(config('minipanel.file_transfer_max_kb') / 1024) }} MB por archivo.</p>
                    <p x-show="uploading" x-cloak role="status">Subiendo archivo… <span x-text="progress + '%'"></span></p>
                    <p x-show="uploadError" x-cloak class="error" role="alert">No se pudo subir el archivo. Vuelve a seleccionarlo; si tu sesión venció, recarga la página.</p>
                    @if($upload)<p role="status">Archivo cargado. Pulsa Aceptar para copiarlo a esta carpeta.</p>@endif
                @endif
                @if(in_array($modal, ['move','copy']))<label>Carpeta destino (relativa a la raíz)<input wire:model="moveDirectory" required></label>@endif
                @if($modal === 'extract')
                    <label>Carpeta destino (relativa a la raíz)<input wire:model="moveDirectory" placeholder="httpdocs" required></label>
                    <label>Si el archivo ya existe<select wire:model="extractMode"><option value="skip">Ignorar existentes</option><option value="replace">Reemplazar existentes</option></select></label>
                    <p class="muted">La carpeta se creará si no existe. Se conserva el ZIP original. Máximo 10 GB descomprimidos y 100000 entradas.</p>
                    <p class="muted">Reemplazar sobrescribe archivos sin respaldo automático. Si ocurre un error, los archivos ya extraídos permanecen en el destino.</p>
                @endif
                @if($modal === 'permissions')<label>Permisos octales<input wire:model="permissions" required pattern="[0-7]{3}" maxlength="3"></label><p class="muted">Ejemplo: 644 para archivo, 755 para carpeta. Sólo se modifica el elemento seleccionado.</p>@endif
                @if($modal === 'delete')<label>Se eliminará definitivamente. Escribe ELIMINAR {{ $selectedPath }}<input wire:model="deleteConfirmation" required autocomplete="off"></label>@endif
                @if($modal === 'download')<p>Prepara el archivo para descargarlo.</p>@if($latestDownload)<a class="primary" href="{{ route('sites.files.download', [$site, $latestDownload]) }}">Descargar archivo</a>@endif @endif
                @foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
                <div class="editor-buttons"><button type="submit" class="{{ $modal === 'delete' ? 'danger-button' : 'primary' }}" :disabled="uploading" wire:loading.attr="disabled">{{ $modal === 'download' ? 'Preparar descarga' : 'Aceptar' }}</button><button type="button" class="secondary" :disabled="uploading" wire:click="$set('modal', '')">Cancelar</button></div>
            </form>
        </section>
    </div>
    @endif
</div>
