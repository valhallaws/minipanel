<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsCheckRun extends Model
{
    protected $fillable = ['site_id', 'status', 'results', 'finished_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
