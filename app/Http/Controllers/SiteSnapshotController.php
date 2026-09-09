<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteSnapshotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Site $site): BinaryFileResponse
    {
        $path = 'site-snapshots/'.$site->id.'.jpg';
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
