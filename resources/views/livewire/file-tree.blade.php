<ul class="explorer-tree" aria-label="Carpetas">
    @foreach($directoryTree[$treePath] ?? [] as $folder)
        <li wire:key="tree-{{ $folder['path'] }}">
            <div class="tree-node {{ $directory === $folder['path'] ? 'active' : '' }}">
                <button type="button" wire:click="toggleDirectory('{{ $folder['path'] }}')" aria-label="Expandir {{ $folder['name'] }}" aria-expanded="{{ in_array($folder['path'], $expandedDirectories, true) ? 'true' : 'false' }}">{{ in_array($folder['path'], $expandedDirectories, true) ? '▾' : '▸' }}</button>
                <button type="button" wire:click="openDirectory('{{ $folder['path'] }}')" x-on:click="query = ''; mobilePanel = 'files'">📁 {{ $folder['name'] }}</button>
            </div>
            @if(in_array($folder['path'], $expandedDirectories, true))
                @include('livewire.file-tree', ['treePath' => $folder['path']])
            @endif
        </li>
    @endforeach
</ul>
