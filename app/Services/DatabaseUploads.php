<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DatabaseUploads
{
    public const MAX_BYTES = 1073741824;

    public function claim(string $token, int $siteId, int $userId): string
    {
        return Cache::lock('database-upload-'.$token, 15)->block(3, function () use ($token, $siteId, $userId): string {
            $metadata = Cache::get('database-upload-'.$token);
            if (! preg_match('/^[a-f0-9]{32}$/D', $token) || ! $metadata || $metadata['site_id'] !== $siteId || $metadata['user_id'] !== $userId || $metadata['received'] !== $metadata['size']) {
                throw ValidationException::withMessages(['uploadToken' => 'Carga incompleta, vencida o no autorizada.']);
            }
            $path = 'database-imports/'.$token.'.sql';
            $disk = Storage::disk('local');
            if (! $disk->move('database-imports/'.$token.'.part', $path)) {
                throw new \RuntimeException('Upload could not be finalized');
            }
            Cache::forget('database-upload-'.$token);

            return $path;
        });
    }
}
