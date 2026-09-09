<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDatabaseOperation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseDumpDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Site $site, SiteDatabaseOperation $operation): BinaryFileResponse
    {
        abort_unless($operation->site_id === $site->id && $operation->user_id === auth()->id(), 404);
        abort_unless($operation->action === 'export' && $operation->status === 'finished' && preg_match('/^[a-f0-9]{32}$/D', $operation->token), 404);
        $path = config('minipanel.database_exports_path', '/var/lib/minipanel/database-exports').'/'.$operation->token.'/dump.zip';
        abort_unless(is_file($path) && ! is_link($path), 404);

        $operation->update(['status' => 'downloaded', 'message' => 'Descarga enviada; ZIP temporal retirado al finalizar el envío.']);

        return response()->download($path, $operation->resource_name.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
