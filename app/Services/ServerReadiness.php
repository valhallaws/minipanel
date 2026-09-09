<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class ServerReadiness
{
    public function inspect(): array
    {
        $checks = [
            $this->command('PHP', ['php', '-v']),
            $this->command('Nginx', ['nginx', '-v']),
            $this->command('Git', ['git', '--version']),
            $this->command('Composer', ['composer', '--version']),
            $this->command('Node.js', ['node', '--version']),
            $this->command('NPM', ['npm', '--version']),
            $this->command('Certbot', ['certbot', '--version']),
            $this->command('DNS utilities', ['dig', '-v']),
            $this->command('Redis', ['redis-cli', 'ping']),
        ];

        $agentInstalled = is_file('/usr/local/bin/minipanel-agent');
        $checks[] = [
            'name' => 'Agente MiniPanel',
            'status' => $agentInstalled ? 'ok' : 'warning',
            'detail' => $agentInstalled ? 'Instalado; acceso delegado mediante sudo' : 'Aún no instalado',
        ];
        $checks[] = [
            'name' => 'Ejecución administrativa',
            'status' => config('minipanel.execution_enabled') ? 'warning' : 'ok',
            'detail' => config('minipanel.execution_enabled') ? 'Habilitada: el agente puede hacer cambios' : 'Deshabilitada: modo seguro',
        ];
        $checks[] = $this->applicationDatabase();

        return $checks;
    }

    private function command(string $name, array $command): array
    {
        try {
            $result = Process::timeout(3)->run($command);

            return [
                'name' => $name,
                'status' => $result->successful() ? 'ok' : 'warning',
                'detail' => $result->successful() ? str(trim($result->output().$result->errorOutput()))->squish()->limit(90)->toString() : 'No disponible',
            ];
        } catch (\Throwable) {
            return ['name' => $name, 'status' => 'warning', 'detail' => 'No disponible'];
        }
    }

    private function applicationDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['name' => 'Base del panel', 'status' => 'ok', 'detail' => 'Conectada ('.config('database.default').')'];
        } catch (\Throwable) {
            return ['name' => 'Base del panel', 'status' => 'warning', 'detail' => 'Sin conexión'];
        }
    }
}
