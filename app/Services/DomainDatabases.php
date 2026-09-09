<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class DomainDatabases
{
    public static function prefix(Site $site): string
    {
        $name = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $site->domain)[0]));

        return 'site_'.substr($name, 0, 12).'_';
    }

    public function apply(Site $site): void
    {
        if ($site->fresh()?->lifecycle_action || $site->trashed()) {
            throw ValidationException::withMessages(['database' => 'El dominio no está disponible durante esta operación.']);
        }
        if (! config('minipanel.execution_enabled')) {
            throw ValidationException::withMessages(['database' => 'La ejecución está desactivada en este equipo. No se modificó MariaDB.']);
        }

        $site->databases()->update(['status' => 'pending']);
        $site->databaseUsers()->update(['status' => 'pending']);
        $databases = $site->databases()->get();
        $payload = [
            'databases' => $databases->map(fn ($database) => ['name' => $database->name, 'collation' => $database->collation])->all(),
            'users' => $site->databaseUsers()->with('additionalDatabases')->get()->map(fn ($user) => [
                'name' => $user->name, 'password' => $user->password,
                'permission' => $user->permission, 'access' => $user->access,
                'ip' => $user->remote_ip,
                'databases' => $databases->filter(fn ($database) => $user->hasAccessTo($database))->pluck('name')->all(),
            ])->all(),
        ];
        $command = [
            'sudo', '/usr/local/bin/minipanel-agent', 'database-sync',
            $site->path, $site->resourceDomain(), '', 'main', $site->php_version, '0', '0', 'static', '',
        ];
        $result = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = Process::timeout(30)->input(json_encode($payload, JSON_THROW_ON_ERROR))->run($command);
            if ($result->successful()) {
                break;
            }
        }
        if (! $result->successful()) {
            throw ValidationException::withMessages(['database' => 'No se completó la aplicación en MariaDB. Los recursos siguen pendientes; revisa la configuración del servidor y reintenta.']);
        }
        $site->databases()->update(['status' => 'active']);
        $site->databaseUsers()->update(['status' => 'active']);
        app(AuditLogger::class)->record('database.applied', $site);
    }
}
