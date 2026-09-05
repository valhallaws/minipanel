<?php

namespace App\Livewire;

use App\Jobs\RunSiteFileOperation;
use App\Models\Site;
use App\Models\SiteFileOperation;
use App\Services\AuditLogger;
use App\Services\SiteFilePath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class FileManager extends Component
{
    use WithFileUploads;

    public Site $site;

    public string $directory = '.';

    public string $viewMode = 'list';

    public string $selectedPath = '';

    public string $contents = '';

    public string $newFolder = '';

    public string $newName = '';

    public string $moveDirectory = '';

    public string $deleteConfirmation = '';

    public ?TemporaryUploadedFile $upload = null;

    public ?int $loadedReadOperationId = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->listDirectory();
    }

    public function listDirectory(): void
    {
        $this->queue('file-list', $this->directory);
    }

    public function setViewMode(string $viewMode): void
    {
        abort_unless(in_array($viewMode, ['list', 'grid'], true), 422);
        $this->viewMode = $viewMode;
    }

    public function openDirectory(string $directory, SiteFilePath $paths): void
    {
        $this->directory = $paths->normalize($directory);
        $this->selectedPath = '';
        $this->contents = '';
        $this->loadedReadOperationId = null;
        $this->listDirectory();
    }

    public function parentDirectory(SiteFilePath $paths): void
    {
        if ($this->directory === '.') {
            return;
        }

        $this->openDirectory($paths->parent($this->directory), $paths);
    }

    public function openFile(string $path, SiteFilePath $paths): void
    {
        $this->selectedPath = $paths->normalize($path, false);
        $this->contents = '';
        $this->loadedReadOperationId = null;
        $this->queue('file-read', $this->selectedPath);
    }

    public function saveFile(SiteFilePath $paths): void
    {
        $this->selectedPath = $paths->normalize($this->selectedPath, false);
        $this->validate(['contents' => ['nullable', 'string', 'max:1048576']]);
        $this->queue('file-write', $this->selectedPath, content: $this->contents);
    }

    public function uploadFile(SiteFilePath $paths): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:'.config('minipanel.file_transfer_max_kb')]]);
        $name = $this->fileName($this->upload->getClientOriginalName(), $paths);
        $path = $paths->join($this->directory, $name);
        $uploadPath = $this->upload->store('file-operations');
        $this->upload = null;
        $this->queue('file-upload', $path, uploadPath: $uploadPath);
    }

    public function createDirectory(SiteFilePath $paths): void
    {
        $path = $paths->join($this->directory, $this->fileName($this->newFolder, $paths));
        $this->queue('file-mkdir', $path);
        $this->newFolder = '';
    }

    public function renameFile(SiteFilePath $paths): void
    {
        $source = $paths->normalize($this->selectedPath, false);
        $destination = $paths->join($paths->parent($source), $this->fileName($this->newName, $paths));
        $this->queue('file-move', $source, $destination);
        $this->selectedPath = '';
        $this->contents = '';
        $this->newName = '';
    }

    public function moveFile(SiteFilePath $paths): void
    {
        $source = $paths->normalize($this->selectedPath, false);
        $destination = $paths->join($paths->normalize($this->moveDirectory), basename($source));
        $this->queue('file-move', $source, $destination);
        $this->selectedPath = '';
        $this->contents = '';
        $this->moveDirectory = '';
    }

    public function deleteFile(SiteFilePath $paths): void
    {
        $path = $paths->normalize($this->selectedPath, false);
        $this->validate(['deleteConfirmation' => ['required', 'in:ELIMINAR '.$path]]);
        $this->queue('file-delete', $path);
        $this->selectedPath = '';
        $this->contents = '';
        $this->deleteConfirmation = '';
    }

    public function downloadFile(SiteFilePath $paths): void
    {
        $this->queue('file-download', $paths->normalize($this->selectedPath, false), downloadPath: Str::uuid()->toString());
    }

    public function refresh(): void
    {
        if ($this->selectedPath === '') {
            return;
        }

        $operation = $this->site->fileOperations()
            ->where('action', 'file-read')
            ->where('path', $this->selectedPath)
            ->where('status', 'finished')
            ->latest('id')
            ->first();

        if ($operation && $operation->id !== $this->loadedReadOperationId) {
            $this->contents = $operation->read_content ?? '';
            $this->loadedReadOperationId = $operation->id;
        }
    }

    private function queue(string $action, string $path, ?string $destination = null, ?string $content = null, ?string $uploadPath = null, ?string $downloadPath = null): void
    {
        $operation = SiteFileOperation::create([
            'site_id' => $this->site->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'path' => $path,
            'destination_path' => $destination,
            'content' => $content,
            'upload_path' => $uploadPath,
            'download_path' => $downloadPath,
            'status' => 'queued',
            'output' => 'Pendiente de ser procesado por el agente del servidor.',
        ]);

        RunSiteFileOperation::dispatch($operation->id);
        app(AuditLogger::class)->record('file.operation_queued', $this->site, ['action' => $action, 'path' => $path, 'operation_id' => $operation->id]);
        session()->flash('notice', 'Operación de archivos puesta en cola.');
    }

    private function fileName(string $name, SiteFilePath $paths): string
    {
        $name = $paths->normalize($name, false);
        abort_if(str_contains($name, '/'), 422, 'Usa sólo un nombre, no una ruta.');

        return $name;
    }

    public function render()
    {
        $this->refresh();
        $listing = $this->site->fileOperations()
            ->where('action', 'file-list')
            ->where('path', $this->directory)
            ->where('status', 'finished')
            ->latest('id')
            ->first();
        $latestDownload = $this->selectedPath === '' ? null : $this->site->fileOperations()
            ->where('action', 'file-download')
            ->where('path', $this->selectedPath)
            ->where('status', 'finished')
            ->latest('id')
            ->first();

        $entries = $listing?->result['entries'] ?? [];

        return view('livewire.file-manager', [
            'entries' => $entries,
            'selectedEntry' => collect($entries)->firstWhere('path', $this->selectedPath),
            'operations' => $this->site->fileOperations()->latest('id')->take(8)->get(),
            'latestDownload' => $latestDownload?->download_path ? $latestDownload : null,
        ])->layout('components.layouts.app');
    }
}
