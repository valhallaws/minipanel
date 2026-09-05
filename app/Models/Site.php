<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'name', 'domain', 'repository', 'deploy_key_public', 'webhook_token', 'webhook_secret', 'branch', 'path', 'php_version', 'php_extensions', 'status',
        'ssl_enabled', 'queue_enabled', 'scheduler_enabled', 'last_deployed_at', 'runtime',
        'artisan_commands', 'npm_scripts', 'environment', 'deploy_commands', 'nginx_config', 'last_inspected_at', 'queue_status', 'scheduler_status',
        'aliases', 'health_url', 'health_status', 'dns_results', 'dns_checked_at', 'health_checked_at', 'last_backup_at', 'last_backup_path', 'current_commit', 'previous_commit', 'last_webhook_at',
    ];

    protected function casts(): array
    {
        return [
            'ssl_enabled' => 'boolean',
            'queue_enabled' => 'boolean',
            'scheduler_enabled' => 'boolean',
            'last_deployed_at' => 'datetime',
            'last_inspected_at' => 'datetime',
            'artisan_commands' => 'array',
            'npm_scripts' => 'array',
            'environment' => 'encrypted',
            'deploy_commands' => 'encrypted',
            'aliases' => 'array',
            'health_checked_at' => 'datetime',
            'dns_checked_at' => 'datetime',
            'dns_results' => 'array',
            'last_backup_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'php_extensions' => 'array',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function queues(): HasMany
    {
        return $this->hasMany(SiteQueue::class);
    }

    public function dnsChecks(): HasMany
    {
        return $this->hasMany(DnsCheckRun::class);
    }

    public function fileOperations(): HasMany
    {
        return $this->hasMany(SiteFileOperation::class);
    }
}
