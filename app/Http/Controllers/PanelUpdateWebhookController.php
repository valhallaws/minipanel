<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Process;

class PanelUpdateWebhookController extends Controller
{
    /**
     * Start a signed Freyja update requested by GitHub.
     */
    public function __invoke(Request $request): Response|JsonResponse
    {
        $payload = $request->getContent();
        $secret = (string) config('minipanel.panel_update_webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $isSignedGithubRequest = $secret !== ''
            && strlen($payload) <= 1048576
            && hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);

        abort_unless($isSignedGithubRequest, 401, 'Invalid webhook signature.');

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->noContent();
        }

        abort_unless($request->header('X-GitHub-Event') === 'push', 422, 'Unsupported webhook event.');

        $data = json_decode($payload, true);
        abort_unless(is_array($data), 422, 'Invalid webhook payload.');

        $branch = (string) config('minipanel.panel_update_branch');
        if (($data['ref'] ?? null) !== 'refs/heads/'.$branch || (bool) ($data['deleted'] ?? false)) {
            return response()->noContent();
        }

        abort_unless(config('minipanel.execution_enabled'), 503, 'Panel execution is disabled.');

        $result = Process::timeout(10)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-panel-update-start']);
        abort_unless($result->successful(), 503, $result->errorOutput() ?: 'Could not start the panel update.');

        return response()->json(['queued' => true], 202);
    }
}
