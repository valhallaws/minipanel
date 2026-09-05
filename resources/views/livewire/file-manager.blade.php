<div wire:poll.1500ms="refresh" x-data="{ query: '' }">
    <header class="topbar">
        <a class="brand" href="/"><span class="mark">MP</span> MiniPanel</a>
        <div class="server">{{ $site->domain }} · Archivos</div>
        <a class="ghost" href="{{ route('sites.show', $site) }}">← Sitio</a>
    </header>

    <section class="file-hero">
        <div><p class="eyebrow">FILE MANAGER</p><h1>{{ $site->name }}</h1><p class="muted">Explorador seguro de <code>{{ $site->path }}</code></p></div>
        <button wire:click="listDirectory" class="secondary" wire:loading.attr="disabled">↻ Actualizar</button>
    </section>

    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif

    <section class="file-explorer">
        <aside class="file-sidebar">
            <p class="file-sidebar-title">UBICACIONES</p>
            <button wire:click="openDirectory('.')" class="file-location {{ $directory === '.' ? 'active' : '' }}"><span>⌂</span> Raíz del sitio</button>
            @if($directory !== '.')<button wire:click="parentDirectory" class="file-location"><span>↑</span> Carpeta superior</button>@endif
            <div class="file-sidebar-rule"></div>
            <p class="file-sidebar-title">ACCIONES</p>
            <form wire:submit="uploadFile" class="file-side-form">
                <label class="file-upload-picker"><span>⇧</span><span>{{ $upload?->getClientOriginalName() ?: 'Elegir archivo' }}</span><input wire:model="upload" type="file"></label>
                <button class="primary" wire:loading.attr="disabled">Subir</button>
                <p class="muted" wire:loading wire:target="upload">Subiendo al panel…</p>
                @error('upload')<p class="error">{{ $message }}</p>@enderror
            </form>
            <form wire:submit="createDirectory" class="file-side-form"><input wire:model="newFolder" placeholder="Nombre de carpeta"><button class="ghost">＋ Nueva carpeta</button></form>
            <p class="file-side-note">Las operaciones se ejecutan en el VPS mediante el worker.</p>
        </aside>

        <main class="file-workspace">
            <div class="file-toolbar">
                <div class="file-breadcrumbs"><button wire:click="openDirectory('.')" class="file-crumb">{{ $site->domain }}</button>@if($directory !== '.')@foreach(explode('/', $directory) as $index => $part)@php($path = implode('/', array_slice(explode('/', $directory), 0, $index + 1)))<span>/</span><button wire:key="crumb-{{ $path }}" wire:click="openDirectory('{{ $path }}')" class="file-crumb">{{ $part }}</button>@endforeach@endif</div>
                <div class="file-view-buttons" aria-label="Vista"><button wire:click="setViewMode('list')" class="{{ $viewMode === 'list' ? 'active' : '' }}" title="Vista de lista">☷</button><button wire:click="setViewMode('grid')" class="{{ $viewMode === 'grid' ? 'active' : '' }}" title="Vista de cuadrícula">▦</button></div>
            </div>
            <div class="file-search"><span>⌕</span><input x-model="query" placeholder="Filtrar esta carpeta…"><small>{{ count($entries) }} elemento(s)</small></div>
            <div class="file-browser {{ $viewMode === 'grid' ? 'is-grid' : '' }}" aria-live="polite">
                @forelse($entries as $entry)
                    <button wire:key="file-{{ $entry['path'] }}" data-name="{{ strtolower($entry['name']) }}" x-show="!query || $el.dataset.name.includes(query.toLowerCase())" wire:click="{{ $entry['type'] === 'directory' ? 'openDirectory' : 'openFile' }}('{{ $entry['path'] }}')" class="file-entry {{ $selectedPath === $entry['path'] ? 'selected' : '' }} {{ $entry['type'] }}">
                        <span class="file-entry-icon">{{ $entry['type'] === 'directory' ? '📁' : '📄' }}</span>
                        <span class="file-entry-name">{{ $entry['name'] }}</span>
                        <span class="file-entry-meta">{{ $entry['type'] === 'directory' ? 'Carpeta' : number_format($entry['size'] / 1024, 1).' KB' }}</span>
                    </button>
                @empty
                    <div class="file-empty"><span>🗂️</span><strong>Carpeta vacía</strong><p>También podría haber una consulta pendiente en el worker.</p></div>
                @endforelse
            </div>
        </main>

        <aside class="file-inspector">
            @if($selectedPath)
                <div class="file-inspector-head"><span class="file-inspector-icon">{{ ($selectedEntry['type'] ?? 'file') === 'directory' ? '📁' : '📄' }}</span><div><p class="file-sidebar-title">SELECCIONADO</p><h2>{{ basename($selectedPath) }}</h2><p>{{ $selectedPath }}</p></div></div>
                <div class="file-inspector-actions"><button wire:click="downloadFile" class="secondary">Preparar descarga</button>@if($latestDownload)<a class="primary" href="{{ route('sites.files.download', [$site, $latestDownload]) }}">Descargar</a>@endif</div>
                <textarea wire:model="contents" class="file-editor" rows="15" spellcheck="false" placeholder="Cargando contenido desde el VPS…"></textarea>
                <button wire:click="saveFile" class="primary file-save" wire:loading.attr="disabled">Guardar cambios</button>
                <details class="file-more-actions"><summary>Renombrar, mover o eliminar</summary><form wire:submit="renameFile" class="file-side-form"><input wire:model="newName" placeholder="Nuevo nombre"><button class="ghost">Renombrar</button></form><form wire:submit="moveFile" class="file-side-form"><input wire:model="moveDirectory" placeholder="Carpeta destino"><button class="ghost">Mover</button></form><form wire:submit="deleteFile" class="file-side-form"><label>Escribe <code>ELIMINAR {{ $selectedPath }}</code><input wire:model="deleteConfirmation" autocomplete="off"></label><button class="danger-button" wire:loading.attr="disabled">Eliminar</button></form></details>
            @else
                <div class="file-inspector-empty"><span>☝</span><strong>Selecciona un archivo</strong><p>Podrás editarlo, descargarlo o moverlo desde aquí.</p></div>
            @endif
        </aside>
    </section>

    <section class="file-operation-drawer">
        <div><strong>Actividad de archivos</strong><span class="muted">{{ count($operations) }} reciente(s)</span></div>
        <div class="file-operation-list">@forelse($operations as $operation)<p wire:key="operation-{{ $operation->id }}"><span class="dot {{ $operation->status }}"></span><strong>{{ str($operation->action)->replace('file-', '') }}</strong> {{ $operation->path }} <small>{{ $operation->status }}</small></p>@empty<p class="muted">Sin operaciones todavía.</p>@endforelse</div>
    </section>
</div>
