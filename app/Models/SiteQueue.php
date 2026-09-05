<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteQueue extends Model
{
    protected $fillable = ['site_id', 'name', 'workers', 'tries', 'timeout', 'status'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
