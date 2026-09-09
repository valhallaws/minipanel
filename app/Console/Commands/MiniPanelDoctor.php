<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class MiniPanelDoctor extends Command
{
    protected $signature = 'minipanel:doctor';

    protected $description = 'Inspect the host prerequisites without changing it';

    public function handle(): int
    {
        $checks = [
            'PHP' => ['php', '-v'],
            'Nginx' => ['nginx', '-v'],
            'Git' => ['git', '--version'],
            'Composer' => ['composer', '--version'],
            'Node' => ['node', '--version'],
            'NPM' => ['npm', '--version'],
            'Certbot' => ['certbot', '--version'],
            'MariaDB/MySQL client' => ['sh', '-lc', 'command -v mariadb || command -v mysql'],
            'Redis' => ['redis-cli', 'ping'],
            'DNS utilities' => ['dig', '-v'],
        ];

        $failed = false;
        foreach ($checks as $name => $command) {
            $result = Process::timeout(5)->run($command);
            $ok = $result->successful();
            $this->line(sprintf('%-24s %s %s', $name, $ok ? '<fg=green>OK</>' : '<fg=red>MISSING</>', $ok ? str(trim($result->output().$result->errorOutput()))->limit(100) : ''));
            $failed = $failed || ! $ok;
        }

        $this->line(sprintf('%-24s %s', 'Agent', is_file('/usr/local/bin/minipanel-agent') ? '<fg=green>OK</>' : '<fg=yellow>NOT INSTALLED</>'));
        $this->line(sprintf('%-24s %s', 'Execution switch', config('minipanel.execution_enabled') ? '<fg=yellow>ENABLED</>' : '<fg=green>DISABLED</>'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
