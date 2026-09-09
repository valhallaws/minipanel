<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class DatabaseUploadController extends Controller
{
    public function start(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->status === 'active', 422);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'regex:/\.sql$/i'], 'size' => ['required', 'integer', 'min:1', 'max:1073741824']]);
        $disk = Storage::disk('local');
        foreach ($disk->files('database-imports') as $file) {
            if (preg_match('/\/[a-f0-9]{32}\.part$/D', $file) && $disk->lastModified($file) < now()->subDay()->timestamp) {
                $disk->delete($file);
            }
        }
        $token = bin2hex(random_bytes(16));
        Cache::put('database-upload-'.$token, ['site_id' => $site->id, 'user_id' => $request->user()->id, 'size' => (int) $data['size'], 'received' => 0], now()->addDay());
        abort_unless(Storage::disk('local')->put('database-imports/'.$token.'.part', ''), 507);

        return response()->json(['token' => $token, 'url' => route('sites.database-uploads.chunk', [$site, $token])]);
    }

    public function chunk(Request $request, Site $site, string $token): JsonResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/D', $token), 404);
        $data = $request->validate(['offset' => ['required', 'integer', 'min:0'], 'chunk' => ['required', 'file', 'max:1024']]);

        return Cache::lock('database-upload-'.$token, 15)->block(3, function () use ($request, $site, $token, $data): JsonResponse {
            $metadata = Cache::get('database-upload-'.$token);
            abort_unless($metadata && $metadata['site_id'] === $site->id && $metadata['user_id'] === $request->user()->id, 404);
            abort_unless((int) $data['offset'] === $metadata['received'], 409);
            $length = $request->file('chunk')->getSize();
            abort_unless($length > 0 && $metadata['size'] >= $metadata['received'] + $length, 422);
            $path = Storage::disk('local')->path('database-imports/'.$token.'.part');
            $destination = fopen($path, 'c+b');
            $source = fopen($request->file('chunk')->getRealPath(), 'rb');
            try {
                ftruncate($destination, $metadata['received']);
                fseek($destination, $metadata['received']);
                abort_unless(stream_copy_to_stream($source, $destination) === $length, 500);
            } finally {
                fclose($source);
                fclose($destination);
            }
            $metadata['received'] += $length;
            Cache::put('database-upload-'.$token, $metadata, now()->addDay());

            return response()->json(['received' => $metadata['received']]);
        });
    }
}
