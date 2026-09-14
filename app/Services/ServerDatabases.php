<?php

namespace App\Services;

use App\Models\ServerDatabaseUser;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class ServerDatabases
{
    public function apply(): void
    {
        if (! config('minipanel.execution_enabled')) {
            throw ValidationException::withMessages(['globalDba' => 'La ejecución está desactivada en este equipo.']);
        }

        $users = ServerDatabaseUser::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ServerDatabaseUser $user): array => ['name' => $user->name, 'password' => $user->password])
            ->all();
        $result = Process::env([
            'HOME' => '/var/www/freyja',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ])->timeout(30)->input(json_encode(['users' => $users], JSON_THROW_ON_ERROR))->run([
            '/usr/bin/sudo', '--non-interactive', '/usr/local/bin/minipanel-agent', 'server-database-dba-sync',
        ]);

        if (! $result->successful()) {
            throw ValidationException::withMessages(['globalDba' => 'No se pudo aplicar el DBA global en MariaDB. Revisa que MariaDB esté activo e inténtalo de nuevo.']);
        }

        ServerDatabaseUser::query()->update(['status' => 'active']);
    }
}
