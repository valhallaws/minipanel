<?php

namespace App\Models;

use Database\Factories\SiteDatabaseOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDatabaseOperation extends Model
{
    /** @use HasFactory<SiteDatabaseOperationFactory> */
    use HasFactory;

    protected $fillable = ['site_id', 'user_id', 'action', 'resource_id', 'resource_name', 'token', 'upload_path', 'status', 'message'];

    protected $hidden = ['upload_path', 'token'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
