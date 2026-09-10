<?php

namespace App\Console\Commands;

use App\Models\ServerSetting;
use Illuminate\Console\Command;

class SeedInstallerSettings extends Command
{
    protected $signature = 'minipanel:seed-installer-settings
        {--panel-path= : Freyja installation path}
        {--panel-user= : Freyja system user}
        {--panel-php-version= : PHP version used by Freyja}
        {--public-ip= : Public IPv4 address of this server}';

    protected $description = 'Persist the installer-provided Freyja server settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $panelPath = (string) $this->option('panel-path');
        $panelUser = (string) $this->option('panel-user');
        $panelPhpVersion = (string) $this->option('panel-php-version');
        $publicIp = (string) $this->option('public-ip');

        if (! preg_match('~^/var/www/[A-Za-z0-9._-]+$~', $panelPath)
            || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $panelUser) !== 1
            || preg_match('/^8\.[0-9]{1,2}$/', $panelPhpVersion) !== 1
            || filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->error('Installer server settings are invalid.');

            return self::FAILURE;
        }

        /** @var array{host?: string|null, port?: int|null, username?: string|null, password?: string|null} $connection */
        $connection = config('database.connections.'.config('database.default'), []);
        $settings = ServerSetting::firstOrNew(['id' => 1]);

        if (! $settings->exists) {
            $settings->fill([
                'db_host' => $connection['host'] ?? '127.0.0.1',
                'db_port' => $connection['port'] ?? 3306,
                'db_username' => $connection['username'] ?? '',
                'db_password' => $connection['password'] ?? '',
            ]);
        }

        $settings->fill([
            'panel_path' => $panelPath,
            'panel_user' => $panelUser,
            'panel_php_version' => $panelPhpVersion,
            'public_ip' => $publicIp,
        ])->save();

        $this->info('Freyja server settings initialized.');

        return self::SUCCESS;
    }
}
