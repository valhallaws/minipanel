<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = ['site_id', 'user_id', 'action', 'parameters', 'status', 'output', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'parameters' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
