<?php

namespace App\Livewire;

use App\Jobs\RunSiteFileOperation;
use App\Models\Site;
use App\Models\SiteFileOperation;
use App\Services\AuditLogger;
use App\Services\SiteFilePath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class FileManager extends Component
{
    use WithFileUploads;

    public Site $site;

    #[Locked]
    public bool $embedded = false;

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

    public ?int $listingOperationId = null;

    #[Locked]
    public array $directoryTree = [];

    public array $expandedDirectories = ['.'];

    public string $sortColumn = 'name';

    public bool $sortDescending = false;

    public bool $editing = false;

    public string $modal = '';

    public string $permissions = '640';

    public string $extractMode = 'skip';

    #[Locked]
    public array $pendingOperations = [];

    public function selectFile(string $path, SiteFilePath $paths): void
    {
        $this->selectedPath = $paths->normalize($path, false);
    }

    public function showModal(string $action): void
    {
        abort_unless(in_array($action, ['create', 'folder', 'upload', 'move', 'copy', 'rename', 'delete', 'compress', 'extract', 'permissions', 'download'], true), 422);
        if (! in_array($action, ['create', 'folder', 'upload'], true)) {
            abort_if($this->selectedPath === '', 422, 'Selecciona un archivo o carpeta.');
        }
        $this->resetErrorBag();
        $this->newName = $action === 'compress' ? basename($this->selectedPath).'.tar.gz' : '';
        $this->moveDirectory = $this->directory;
        $this->extractMode = 'skip';
        $this->deleteConfirmation = '';
        $this->modal = $action;
    }

    public function closeEditor(): void
    {
        $this->editing = false;
        $this->contents = '';
        $this->loadedReadOperationId = null;
    }

    public function submitModal(SiteFilePath $paths): void
    {
        $this->resetErrorBag();
        if ($this->modal === 'upload') {
            $this->uploadFile($paths);
        } elseif ($this->modal === 'folder') {
            $this->createDirectory($paths);
        } elseif ($this->modal === 'create') {
            $this->queue('file-create', $paths->join($this->directory, $this->fileName($this->newName, $paths)));
        } elseif ($this->modal === 'rename') {
            $this->renameFile($paths);
        } elseif ($this->modal === 'delete') {
            $this->deleteFile($paths);
        } elseif ($this->modal === 'download') {
            $this->downloadFile($paths);

            return;
        } elseif (in_array($this->modal, ['move', 'copy'], true)) {
            $source = $paths->normalize($this->selectedPath, false);
            $destination = $paths->join($paths->normalize($this->moveDirectory), basename($source));
            $this->queue($this->modal === 'move' ? 'file-move' : 'file-copy', $source, $destination);
        } elseif ($this->modal === 'extract') {
            abort_unless(Auth::check(), 403);
            $this->validate(['selectedPath' => ['required', 'regex:/\.zip$/i'], 'extractMode' => ['required', 'in:skip,replace']]);
            $this->queue('file-extract', $paths->normalize($this->selectedPath, false), $paths->normalize($this->moveDirectory), $this->extractMode);
        } elseif ($this->modal === 'compress') {
            $this->validate(['newName' => ['required', 'ends_with:.tar.gz']]);
            $this->queue('file-compress', $paths->normalize($this->selectedPath, false), $paths->join($this->directory, $this->fileName($this->newName, $paths)));
        } elseif ($this->modal === 'permissions') {
            $this->validate(['permissions' => ['required', 'regex:/^[0-7]{3}$/']]);
            $this->queue('file-chmod', $paths->normalize($this->selectedPath, false), $this->permissions);
        } else {
            abort(422);
        }
        $this->modal = '';
    }

    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, ['name', 'size', 'modified_at'], true), 422);
        $this->sortDescending = $this->sortColumn === $column && ! $this->sortDescending;
        $this->sortColumn = $column;
    }

    public function toggleDirectory(string $path, SiteFilePath $paths): void
    {
        $path = $paths->normalize($path);

        if (in_array($path, $this->expandedDirectories, true)) {
            $this->expandedDirectories = array_values(array_diff($this->expandedDirectories, [$path]));

            return;
        }

        $operation = $this->queue('file-list', $path);
        if ($operation->status !== 'finished') {
            $this->addError('directory', $operation->output);

            return;
        }

        $this->directoryTree[$path] = array_values(array_filter($operation->result['entries'] ?? [], fn (array $entry): bool => $entry['type'] === 'directory'));
        $this->expandedDirectories[] = $path;
    }

    public function mount(Site $site, bool $embedded = false): void
    {
        $this->site = $site;
        $this->embedded = $embedded;
        $this->listDirectory();
    }

    public function listDirectory(): void
    {
        $this->directory = app(SiteFilePath::class)->normalize($this->directory);
        $operation = $this->queue('file-list', $this->directory);
        $this->listingOperationId = $operation->id;
        $this->resetErrorBag('directory');

        if ($operation->status !== 'finished') {
            $this->addError('directory', $operation->output ?: 'No se pudo abrir la carpeta.');
        } else {
            $this->directoryTree[$this->directory] = array_values(array_filter($operation->result['entries'] ?? [], fn (array $entry): bool => $entry['type'] === 'directory'));
            if (! in_array($this->directory, $this->expandedDirectories, true)) {
                $this->expandedDirectories[] = $this->directory;
            }
        }
    }

    public function setViewMode(string $viewMode): void
    {
        abort_unless(in_array($viewMode, ['list', 'grid'], true), 422);
        $this->viewMode = $viewMode;
    }

    public function openDirectory(string $directory, SiteFilePath $paths): void
    {
        $this->directory = $paths->normalize($directory);
        $this->editing = false;
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
        $operation = $this->queue('file-read', $this->selectedPath);
        $this->resetErrorBag('file');
        $this->editing = $operation->status === 'finished';
        if ($this->editing) {
            $this->contents = $operation->read_content ?? '';
            $this->loadedReadOperationId = $operation->id;
        } else {
            $this->addError('file', $operation->output);
        }
    }

    public function saveFile(SiteFilePath $paths): void
    {
        $this->selectedPath = $paths->normalize($this->selectedPath, false);
        $this->validate(['contents' => ['nullable', 'string', 'max:1048576']]);
        abort_unless($this->editing && $this->loadedReadOperationId !== null, 422);
        $operation = $this->queue('file-write', $this->selectedPath, content: $this->contents);
        if ($operation->status !== 'finished') {
            $this->addError('file', $operation->output);
        } else {
            session()->flash('notice', 'Archivo guardado.');
        }
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
        $completed = $this->site->fileOperations()->whereIn('id', $this->pendingOperations)->whereIn('status', ['finished', 'failed', 'blocked'])->get();
        if ($completed->isNotEmpty()) {
            $this->pendingOperations = array_values(array_diff($this->pendingOperations, $completed->modelKeys()));
            foreach ($completed as $operation) {
                if ($operation->status !== 'finished') {
                    $this->addError('file', $operation->output);
                }
            }
            $this->listDirectory();
        }
    }

    private function queue(string $action, string $path, ?string $destination = null, ?string $content = null, ?string $uploadPath = null, ?string $downloadPath = null): SiteFileOperation
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

        if (in_array($action, ['file-list', 'file-read', 'file-write'], true)) {
            $job = new RunSiteFileOperation($operation->id);
            $job->timeout = 10;

            try {
                $job->handle();
            } catch (Throwable $exception) {
                $job->failed($exception);
            }

            if ($action === 'file-write') {
                app(AuditLogger::class)->record('file.operation_executed', $this->site, ['action' => $action, 'path' => $path, 'operation_id' => $operation->id]);
            }

            return $operation->refresh();
        }

        RunSiteFileOperation::dispatch($operation->id);
        $this->pendingOperations[] = $operation->id;
        app(AuditLogger::class)->record('file.operation_queued', $this->site, ['action' => $action, 'path' => $path, 'operation_id' => $operation->id]);
        session()->flash('notice', 'Operación de archivos puesta en cola.');

        return $operation;
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
            ->whereKey($this->listingOperationId)
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
        usort($entries, function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'directory' ? -1 : 1;
            }

            $comparison = $this->sortColumn === 'name'
                ? strnatcasecmp($left['name'], $right['name'])
                : $left[$this->sortColumn] <=> $right[$this->sortColumn];

            return $this->sortDescending ? -$comparison : $comparison;
        });

        $view = view('livewire.file-manager', [
            'entries' => $entries,
            'selectedEntry' => collect($entries)->firstWhere('path', $this->selectedPath),
            'operations' => $this->site->fileOperations()->latest('id')->take(8)->get(),
            'latestDownload' => $latestDownload?->download_path ? $latestDownload : null,
        ]);

        return $this->embedded ? $view : $view->layout('components.layouts.app');
    }
}
