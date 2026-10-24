<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAlert extends Model
{
    protected $fillable = ['site_id', 'type', 'status', 'message', 'last_notified_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
