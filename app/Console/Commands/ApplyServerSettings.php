<?php

namespace App\Console\Commands;

use App\Models\ServerSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ApplyServerSettings extends Command
{
    protected $signature = 'minipanel:apply-server-settings';

    protected $description = 'Apply encrypted MariaDB provisioning credentials to the root-owned VPS agent';

    public function handle(): int
    {
        abort_unless(config('minipanel.execution_enabled'), 403, 'Set MINIPANEL_EXECUTION_ENABLED=true on the target VPS first.');
        $settings = ServerSetting::first();
        if (! $settings) {
            $this->error('Configure the server in MiniPanel first.');

            return self::FAILURE;
        }
        $input = implode("\n", [$settings->db_host, $settings->db_port, $settings->db_username, $settings->db_password])."\n";
        $result = Process::input($input)->run(['sudo', '/usr/local/bin/minipanel-agent', 'configure-mariadb']);
        if (! $result->successful()) {
            $this->error(trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }
        $settings->update(['applied_at' => now()]);
        $this->info('MariaDB provisioning credentials applied to the VPS agent.');

        return self::SUCCESS;
    }
}
