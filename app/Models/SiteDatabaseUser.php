<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SiteDatabaseUser extends Model
{
    protected $fillable = ['site_id', 'site_database_id', 'all_databases', 'name', 'password', 'permission', 'access', 'remote_ip', 'status'];

    protected $hidden = ['password'];

    public function additionalDatabases(): BelongsToMany
    {
        return $this->belongsToMany(SiteDatabase::class, 'site_database_grants');
    }

    public function hasAccessTo(SiteDatabase $database): bool
    {
        return $database->site_id === $this->site_id && (
            (! $this->site_database_id && $this->all_databases)
            || $this->site_database_id === $database->id
            || $this->additionalDatabases->contains('id', $database->id)
        );
    }

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'all_databases' => 'boolean'];
    }
}
