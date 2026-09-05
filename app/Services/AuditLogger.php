<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $event, ?Model $subject = null, array $metadata = [], ?int $userId = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => str($request?->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
