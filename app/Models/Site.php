<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'server_domain', 'pending_domain', 'lifecycle_action', 'lifecycle_token', 'lifecycle_error', 'lifecycle_previous_status', 'trash_group', 'purge_after',
        'parent_site_id',
        'document_root', 'hosting_limits', 'ssl_auto_renew',
        'name', 'domain', 'repository', 'deploy_key_public', 'webhook_token', 'webhook_secret', 'branch', 'path', 'php_version', 'node_version', 'php_extensions', 'status',
        'ssl_enabled', 'queue_enabled', 'scheduler_enabled', 'last_deployed_at', 'runtime',
        'artisan_commands', 'npm_scripts', 'environment', 'deploy_commands', 'nginx_config', 'last_inspected_at', 'queue_status', 'scheduler_status',
        'aliases', 'health_url', 'health_status', 'dns_results', 'dns_checked_at', 'health_checked_at', 'last_backup_at', 'last_backup_path', 'current_commit', 'previous_commit', 'last_webhook_at',
    ];

    protected function casts(): array
    {
        return [
            'purge_after' => 'datetime',
            'ssl_enabled' => 'boolean',
            'ssl_auto_renew' => 'boolean',
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
            'hosting_limits' => 'array',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function resourceDomain(): string
    {
        return $this->server_domain ?? $this->domain;
    }

    public function databases(): HasMany
    {
        return $this->hasMany(SiteDatabase::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(SiteRepository::class);
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(SiteDatabaseUser::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_site_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_site_id')->orderBy('domain');
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
