<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteFileOperation;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteFileDownloadController extends Controller
{
    public function __invoke(Site $site, SiteFileOperation $operation): BinaryFileResponse
    {
        abort_unless($operation->site_id === $site->id, 404);
        abort_unless($operation->action === 'file-download' && $operation->status === 'finished' && $operation->download_path !== null, 404);
        $downloadPath = rtrim(config('minipanel.download_path'), '/').'/'.$operation->download_path;
        abort_unless(File::isFile($downloadPath), 404);

        return response()->download($downloadPath, basename($operation->path), ['Content-Type' => 'application/octet-stream']);
    }
}
