<?php

namespace App\Livewire;

use App\Jobs\RunDatabaseOperation;
use App\Models\ServerSetting;
use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use App\Services\AuditLogger;
use App\Services\DatabaseStatistics;
use App\Services\DatabaseUploads;
use App\Services\DomainDatabases;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DatabaseManager extends Component
{
    #[Locked]
    public ?int $operationResourceId = null;

    #[Locked]
    public string $operationName = '';

    #[Locked]
    public ?string $operationAction = null;

    public string $uploadToken = '';

    public function prepareOperation(string $action, int $id): void
    {
        abort_unless(Auth::check() && in_array($action, ['export', 'import', 'delete-database', 'delete-user'], true), 403);
        $resource = $action === 'delete-user' ? $this->site->databaseUsers()->findOrFail($id) : $this->site->databases()->findOrFail($id);
        $this->resetValidation();
        $this->reset('uploadToken');
        $this->operationResourceId = $resource->id;
        $this->operationName = $resource->name;
        $this->operationAction = $action;
        $this->modal = 'operation';
    }

    public function confirmOperation(): void
    {
        abort_unless(Auth::check() && $this->site->fresh()?->status === 'active' && ! $this->site->fresh()?->lifecycle_action, 403);
        abort_unless(in_array($this->operationAction, ['export', 'import', 'delete-database', 'delete-user'], true), 422);
        if (! config('minipanel.execution_enabled')) {
            $this->addError('database', 'La ejecución está desactivada.');

            return;
        }
        $resource = $this->operationAction === 'delete-user' ? $this->site->databaseUsers()->findOrFail($this->operationResourceId) : $this->site->databases()->findOrFail($this->operationResourceId);
        $upload = null;
        if ($this->operationAction === 'import') {
            $this->validate(['uploadToken' => ['required', 'regex:/^[a-f0-9]{32}$/D']]);
            $upload = app(DatabaseUploads::class)->claim($this->uploadToken, $this->site->id, Auth::id());
        }
        $operation = SiteDatabaseOperation::create([
            'site_id' => $this->site->id, 'user_id' => Auth::id(), 'action' => $this->operationAction,
            'resource_id' => $resource->id, 'resource_name' => $resource->name,
            'token' => bin2hex(random_bytes(16)), 'upload_path' => $upload,
        ]);
        RunDatabaseOperation::dispatch($operation->id)->afterCommit();
        $this->closeModal();
        session()->flash('notice', 'Operación en cola. El resultado aparecerá aquí automáticamente.');
    }

    public Site $site;

    #[Locked]
    public bool $embedded = false;

    public string $databaseName = '';

    public string $databaseCollation = 'utf8mb4_spanish2_ci';

    public string $username = '';

    public string $password = '';

    public string $permission = 'admin';

    public string $access = 'local';

    public string $remoteIp = '';

    public ?int $databaseId = null;

    public ?int $editingUserId = null;

    public bool $confirmRemote = false;

    public ?string $modal = null;

    public string $databaseUserMode = 'none';

    public ?int $existingDatabaseUserId = null;

    #[Locked]
    public array $statistics = [];

    #[Locked]
    public string $statisticsError = '';

    public function refreshStatistics(bool $force = false): void
    {
        abort_unless(Auth::check(), 403);
        if ($this->modal && ! $force) {
            return;
        }
        try {
            if ($force) {
                DatabaseStatistics::forget($this->site);
            }
            $this->statistics = app(DatabaseStatistics::class)->read($this->site);
            $this->statisticsError = '';
        } catch (\Throwable) {
            $this->statisticsError = 'No se pudieron actualizar las estadísticas. Reintenta; los valores previos pueden estar desactualizados.';
        }
    }

    public function newDatabase(): void
    {
        $this->resetValidation();
        $this->reset('databaseName', 'databaseUserMode', 'existingDatabaseUserId', 'username', 'password', 'permission', 'access', 'remoteIp', 'confirmRemote', 'editingUserId');
        $this->databaseCollation = 'utf8mb4_spanish2_ci';
        $this->modal = 'database';
    }

    public function closeModal(): void
    {
        $this->resetValidation();
        $this->reset('modal', 'password', 'uploadToken', 'operationResourceId', 'operationAction', 'operationName');
    }

    public function mount(Site $site, bool $embedded = false): void
    {
        $this->site = $site;
        $this->embedded = $embedded;
    }

    public function createDatabase(): void
    {
        $this->mutate(function (): void {
            $this->validate([
                'databaseName' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', Rule::unique('site_databases', 'name')],
                'databaseCollation' => ['required', Rule::in(['utf8mb4_spanish2_ci', 'utf8mb4_unicode_ci'])],
            ]);
            $this->validate(['databaseUserMode' => [Rule::in(['none', 'new', 'existing'])]]);
            if ($this->databaseUserMode === 'new') {
                $this->validate([
                    'username' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', Rule::unique('site_database_users', 'name')],
                    'password' => ['required', 'string', 'min:12', 'max:128'],
                    'permission' => [Rule::in(['read', 'write', 'admin'])],
                    'access' => [Rule::in(['local', 'ip', 'any'])],
                    'remoteIp' => ['nullable', 'required_if:access,ip', 'ip'],
                    'confirmRemote' => [Rule::when($this->access !== 'local', 'accepted')],
                ]);
            } elseif ($this->databaseUserMode === 'existing') {
                $this->validate(['existingDatabaseUserId' => ['required', 'integer', Rule::exists('site_database_users', 'id')->where('site_id', $this->site->id)]]);
            }
            DB::transaction(function (): void {
                $database = $this->site->databases()->create(['name' => $this->databaseName, 'collation' => $this->databaseCollation]);
                if ($this->databaseUserMode === 'new') {
                    $this->site->databaseUsers()->create([
                        'name' => $this->username, 'password' => $this->password,
                        'site_database_id' => $database->id, 'all_databases' => false,
                        'permission' => $this->permission, 'access' => $this->access,
                        'remote_ip' => $this->access === 'ip' ? $this->remoteIp : null,
                    ]);
                } elseif ($this->databaseUserMode === 'existing') {
                    $user = $this->site->databaseUsers()->findOrFail($this->existingDatabaseUserId);
                    if (! $user->hasAccessTo($database)) {
                        $user->additionalDatabases()->syncWithoutDetaching([$database->id]);
                    }
                }
            });
            app(AuditLogger::class)->record('database.created', $this->site);
        });
        $this->reset('databaseName');
        $this->closeModal();
        $this->refreshStatistics(true);
    }

    public function editUser(int $id): void
    {
        abort_unless(Auth::check(), 403);
        $user = $this->site->databaseUsers()->findOrFail($id);
        $this->editingUserId = $user->id;
        $this->username = $user->name;
        $this->password = '';
        $this->permission = $user->permission;
        $this->access = $user->access;
        $this->remoteIp = $user->remote_ip ?? '';
        $this->databaseId = $user->site_database_id ?? ($user->all_databases ? null : 0);
        $this->confirmRemote = false;
        $this->resetValidation();
        $this->modal = 'user';
    }

    public function newUser(): void
    {
        $this->reset('editingUserId', 'username', 'password', 'permission', 'access', 'remoteIp', 'databaseId', 'confirmRemote');
        $this->resetValidation();
        $this->modal = 'user';
    }

    public function saveUser(): void
    {
        $this->validate([
            'username' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D'],
            'password' => [$this->editingUserId ? 'nullable' : 'required', 'string', 'min:12', 'max:128'],
            'permission' => [Rule::in(['read', 'write', 'admin'])],
            'access' => [Rule::in(['local', 'ip', 'any'])],
            'remoteIp' => ['nullable', 'required_if:access,ip', 'ip'],
            'confirmRemote' => [Rule::when($this->access !== 'local', 'accepted')],
            'databaseId' => ['nullable', 'integer', Rule::when($this->databaseId !== 0, Rule::exists('site_databases', 'id')->where('site_id', $this->site->id))],
        ]);
        $this->mutate(function (): void {
            $user = $this->editingUserId ? $this->site->databaseUsers()->findOrFail($this->editingUserId) : null;
            if (! $user) {
                $this->validate(['username' => [Rule::unique('site_database_users', 'name')]]);
            }
            $data = ['permission' => $this->permission, 'access' => $this->access, 'remote_ip' => $this->access === 'ip' ? $this->remoteIp : null, 'site_database_id' => $this->databaseId ?: null, 'all_databases' => $this->databaseId === null, 'status' => 'pending'];
            if ($this->password !== '') {
                $data['password'] = $this->password;
            }
            if ($user) {
                if ($user->site_database_id !== ($this->databaseId ?: null) || $user->all_databases !== ($this->databaseId === null)) {
                    $user->additionalDatabases()->detach();
                }
                $user->update($data);
            } else {
                $this->site->databaseUsers()->create(['name' => $this->username, ...$data]);
            }
            app(AuditLogger::class)->record('database.user.saved', $this->site);
        });
        $this->password = '';
        $this->closeModal();
    }

    public function apply(): void
    {
        $this->mutate(fn () => null);
    }

    private function mutate(callable $callback): void
    {
        abort_unless(Auth::check(), 403);
        if ($this->site->fresh()->lifecycle_action || $this->site->fresh()->status !== 'active') {
            throw ValidationException::withMessages(['database' => 'El dominio no está activo. Completa su aprovisionamiento o reactívalo antes de administrar sus bases.']);
        }
        Cache::lock('domain-databases', 60)->block(3, function () use ($callback): void {
            $callback();
            app(DomainDatabases::class)->apply($this->site);
        });
        session()->flash('notice', 'Bases y permisos aplicados. El .env no fue modificado.');
    }

    public function render()
    {
        $view = view('livewire.database-manager', [
            'databases' => $this->site->databases()->orderBy('name')->get(),
            'databaseUsers' => $this->site->databaseUsers()->with('additionalDatabases')->orderBy('name')->get(),
            'settings' => ServerSetting::first(),
            'operations' => SiteDatabaseOperation::where('site_id', $this->site->id)->where('user_id', Auth::id())->latest()->limit(8)->get(),
            'prefix' => DomainDatabases::prefix($this->site),
        ]);

        return $this->embedded ? $view : $view->layout('components.layouts.app');
    }
}
