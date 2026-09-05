<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteFileOperation extends Model
{
    protected $fillable = [
        'site_id', 'user_id', 'action', 'path', 'destination_path', 'content', 'read_content', 'upload_path', 'download_path',
        'status', 'output', 'result', 'started_at', 'finished_at',
    ];

    protected $hidden = ['content', 'read_content', 'upload_path', 'download_path'];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'read_content' => 'encrypted',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
