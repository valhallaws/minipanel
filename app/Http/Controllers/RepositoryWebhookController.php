<?php

namespace App\Http\Controllers;

use App\Models\SiteRepository;
use App\Services\DomainGit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RepositoryWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, SiteRepository $repository, DomainGit $git): JsonResponse
    {
        $secret = (string) $repository->webhook_secret;
        abort_unless($secret !== '' && strlen($request->getContent()) <= 1048576, 401);
        $github = $request->header('X-GitHub-Event') === 'push' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256'));
        $gitlab = $request->header('X-Gitlab-Event') === 'Push Hook' && hash_equals($secret, (string) $request->header('X-Gitlab-Token'));
        abort_unless($github || $gitlab, 401);
        $payload = $request->all();
        if (! isset($payload['payload']) && str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($request->getContent(), $payload);
        }
        if (is_string($payload['payload'] ?? null)) {
            $payload = json_decode($payload['payload'], true) ?? [];
        }
        if (! $repository->automatic || ! $repository->branch || ($payload['ref'] ?? null) !== 'refs/heads/'.$repository->branch || (bool) ($payload['deleted'] ?? false) || ! $repository->site) {
            return response()->json(['queued' => false], 202);
        }
        try {
            $repository->update(['last_push_at' => now()]);
            $git->sync($repository);
        } catch (ValidationException) {
            return response()->json(['queued' => false, 'reason' => 'Domain unavailable or busy; retry later.'], 409);
        }

        return response()->json(['queued' => true], 202);
    }
}
