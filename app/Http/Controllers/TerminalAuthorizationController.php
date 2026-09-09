<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TerminalAuthorizationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user(), 401);

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $expiresAt = now()->subSeconds((int) config('auth.password_timeout', 10800))->timestamp;

        abort_unless($confirmedAt >= $expiresAt, 403);

        return response()->noContent();
    }
}
