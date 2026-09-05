<?php

namespace App\Http\Controllers;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        $payload = $request->json()->all();
        $signature = (string) $request->header('X-Hub-Signature-256');
        $githubValid = hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), (string) $site->webhook_secret), $signature);
        $gitlabValid = hash_equals((string) $site->webhook_secret, (string) $request->header('X-Gitlab-Token'));
        abort_unless($githubValid || $gitlabValid, 401, 'Invalid webhook signature.');

        $ref = (string) ($payload['ref'] ?? '');
        if ($ref !== 'refs/heads/'.$site->branch) {
            return response()->json(['queued' => false, 'reason' => 'different branch'], 202);
        }

        $deployment = Deployment::create([
            'site_id' => $site->id, 'action' => 'deploy', 'status' => 'queued',
            'output' => 'Despliegue solicitado por webhook de push.',
        ]);
        $site->update(['last_webhook_at' => now()]);
        RunDeployment::dispatch($deployment->id);

        return response()->json(['queued' => true, 'deployment_id' => $deployment->id], 202);
    }
}
