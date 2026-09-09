<article class="panel hosting-settings">
    <h2>Hosting</h2>
    <p class="muted">Ruta relativa a {{ $site->path }}. Usa «.» para la raíz. La carpeta debe existir; no se moverán ni borrarán archivos.</p>
    <form wire:submit="saveHosting" class="form-grid">
        <label>Carpeta pública<input wire:model="documentRoot" placeholder="public"></label>
        <label>Versión PHP<select wire:model="hostingPhpVersion">@foreach($this->phpVersions() as $version)<option value="{{ $version }}">PHP {{ $version }}</option>@endforeach</select></label>
        <label>Versión Node.js<select wire:model="hostingNodeVersion">@foreach($this->nodeVersions() as $node)<option value="{{ $node['version'] }}">Node.js {{ $node['version'] }} · {{ $node['state'] }}</option>@endforeach</select></label>
        <label>Límite de subida<select wire:model="hostingUploadLimit"><option>64M</option><option>128M</option><option>256M</option><option>512M</option><option>1g</option><option>2g</option></select></label>
        <label>Memoria PHP<select wire:model="hostingMemoryLimit"><option>128M</option><option>256M</option><option>512M</option><option>768M</option><option>1G</option></select></label>
        <label>Timeout PHP<select wire:model="hostingExecutionTimeout"><option value="30">30 segundos</option><option value="60">60 segundos</option><option value="120">2 minutos</option><option value="300">5 minutos</option><option value="600">10 minutos</option></select></label>
        <button class="primary" wire:loading.attr="disabled">Aplicar hosting</button>
    </form>
    @error('hosting')<p class="error">{{ $message }}</p>@enderror
    @error('documentRoot')<p class="error">{{ $message }}</p>@enderror
    @error('hostingPhpVersion')<p class="error">{{ $message }}</p>@enderror
    @error('hostingNodeVersion')<p class="error">{{ $message }}</p>@enderror
    @error('hostingUploadLimit')<p class="error">{{ $message }}</p>@enderror
    @error('hostingMemoryLimit')<p class="error">{{ $message }}</p>@enderror
    @error('hostingExecutionTimeout')<p class="error">{{ $message }}</p>@enderror
</article>
