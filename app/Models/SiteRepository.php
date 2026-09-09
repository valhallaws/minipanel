<?php

namespace App\Models;

use Database\Factories\SiteRepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteRepository extends Model
{
    /** @use HasFactory<SiteRepositoryFactory> */
    use HasFactory;

    protected $fillable = ['site_id', 'key_token', 'name', 'url', 'directory', 'public_key', 'branch', 'branches', 'commits', 'project_type', 'prepare_project', 'automatic', 'webhook_secret', 'status', 'operation_token', 'steps', 'error', 'synced_at', 'last_push_at'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return ['branches' => 'array', 'commits' => 'array', 'steps' => 'array', 'prepare_project' => 'boolean', 'automatic' => 'boolean', 'webhook_secret' => 'encrypted', 'synced_at' => 'datetime', 'last_push_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
