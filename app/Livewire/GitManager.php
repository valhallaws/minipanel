<?php

namespace App\Livewire;

use App\Models\Site;
use App\Services\DomainGit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GitManager extends Component
{
    public Site $site;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public ?int $progressId = null;

    #[Locked]
    public array $folders = [];

    public bool $showCreate = false;

    public bool $showFolders = false;

    public string $name = '';

    public string $url = '';

    public string $directory = 'httpdocs';

    public string $folderPath = '.';

    public string $folderName = '';

    public bool $prepareProject = true;

    public array $selectedBranches = [];

    public string $siteDeployCommands = '';

    public function mount(Site $site, bool $embedded = false): void
    {
        abort_unless(Auth::check(), 403);
        $this->site = $site;
        $this->embedded = $embedded;
        $this->siteDeployCommands = $site->deploy_commands ?? '';
    }

    public function openCreate(DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetValidation();
        $draft = $git->draft($this->site);
        $this->draftId = $draft->id;
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->showFolders = false;
    }

    public function browse(string $path, DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $this->folders = $git->quick($this->site, 'folders', ['directory' => $path])['folders'];
        $this->folderPath = $path;
        $this->showFolders = true;
    }

    public function createFolder(DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $this->validate(['folderName' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]{0,100}$/D']]);
        $path = ($this->folderPath === '.' ? '' : $this->folderPath.'/').$this->folderName;
        $git->quick($this->site, 'mkdir', ['directory' => $path]);
        $this->reset('folderName');
        $this->browse($path, $git);
    }

    public function selectFolder(): void
    {
        $this->directory = $this->folderPath;
        $this->showFolders = false;
    }

    public function createRepository(DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $git->available($this->site);
        $this->validate([
            'name' => ['required', 'max:80', Rule::unique('site_repositories', 'name')->where('site_id', $this->site->id)],
            'url' => ['required', 'max:512', 'regex:~^(?:git@[A-Za-z0-9][A-Za-z0-9.-]*:[A-Za-z0-9_][A-Za-z0-9._/-]*|ssh://git@[A-Za-z0-9][A-Za-z0-9.-]*(?::[0-9]{1,5})?/[A-Za-z0-9_][A-Za-z0-9._/-]*)$~D'],
            'directory' => ['required', 'max:255', 'regex:~^[A-Za-z0-9][A-Za-z0-9._ -]*(?:/[A-Za-z0-9][A-Za-z0-9._ -]*)*$~D'],
            'prepareProject' => ['boolean'],
        ]);
        DB::transaction(function () use ($git): void {
            $site = Site::whereKey($this->site->id)->lockForUpdate()->firstOrFail();
            $git->available($site);
            $draft = $site->repositories()->where('status', 'draft')->findOrFail($this->draftId);
            foreach ($site->repositories()->whereNotNull('directory')->get() as $existing) {
                if ($existing->directory === $this->directory || str_starts_with($existing->directory.'/', $this->directory.'/') || str_starts_with($this->directory.'/', $existing->directory.'/')) {
                    throw ValidationException::withMessages(['directory' => 'La carpeta se cruza con otro repositorio del dominio. Elige una ubicación independiente.']);
                }
            }
            $draft->update(['name' => $this->name, 'url' => $this->url, 'directory' => $this->directory, 'prepare_project' => $this->prepareProject, 'status' => 'configured']);
            $git->sync($draft);
            $this->progressId = $draft->id;
        });
        $this->closeCreate();
        $this->reset('draftId', 'name', 'url', 'directory', 'prepareProject');
    }

    public function syncRepository(int $id, DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $repository = $this->site->repositories()->find($id);
        abort_unless($repository, 404);
        $git->sync($repository, $this->selectedBranches[$id] ?? null);
        $this->progressId = $id;
    }

    public function showProgress(int $id): void
    {
        abort_unless(Auth::check(), 403);
        $this->progressId = $this->site->repositories()->findOrFail($id)->id;
    }

    public function setAutomatic(int $id, bool $enabled, DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $git->available($this->site);
        $repository = $this->site->repositories()->findOrFail($id);
        $repository->update(['automatic' => $enabled, 'webhook_secret' => $repository->webhook_secret ?: Str::random(64)]);
    }

    public function setPreparation(int $id, bool $enabled, DomainGit $git): void
    {
        abort_unless(Auth::check(), 403);
        $git->available($this->site);
        $repository = $this->site->repositories()->findOrFail($id);
        abort_if(in_array($repository->status, ['queued', 'running'], true), 409);
        $repository->update(['prepare_project' => $enabled]);
    }

    public function saveSiteDeployCommands(): void
    {
        abort_unless(Auth::check(), 403);
        if (mb_strlen($this->siteDeployCommands) > 50000) {
            $this->addError('siteDeployCommands', 'El script no puede superar 50 KB.');

            return;
        }
        foreach (preg_split('/\R/', $this->siteDeployCommands) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^(?:php artisan|composer|npm)(?:\s+[A-Za-z0-9_.,:@=+\/-]+)*$/', $line)) {
                $this->addError('siteDeployCommands', 'Cada línea debe iniciar con php artisan, composer o npm y no puede contener operadores de shell.');

                return;
            }
        }
        $this->site->update(['deploy_commands' => $this->siteDeployCommands]);
        session()->flash('notice', 'Script post-deploy guardado cifrado. Se ejecutará después de la preparación automática.');
    }

    public function minimizeProgress(): void
    {
        $this->progressId = null;
    }

    public function render(): View
    {
        $progress = $this->progressId ? $this->site->repositories()->find($this->progressId) : null;
        if ($progress?->status === 'ready') {
            $this->progressId = null;
            $progress = null;
        }

        $view = view('livewire.git-manager', [
            'repositories' => $this->site->repositories()->where('status', '!=', 'draft')->orderBy('id')->get(),
            'draft' => $this->draftId ? $this->site->repositories()->find($this->draftId) : null,
            'progress' => $progress,
        ]);

        return $this->embedded ? $view : $view->layout('components.layouts.app');
    }
}
