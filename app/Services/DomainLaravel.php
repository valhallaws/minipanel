<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Auth;

class DomainLaravel
{
    public function directory(Site $site, int $repositoryId): string
    {
        abort_unless(Auth::check(), 403);
        $site = $site->fresh();
        abort_unless($site && ! $site->lifecycle_action && $site->status === 'active', 422, 'El dominio debe estar activo y disponible.');
        if ($repositoryId === 0) {
            abort_unless($site->runtime === 'laravel', 404);

            return '.';
        }
        $repository = $site->repositories()->where('project_type', 'Laravel')->findOrFail($repositoryId);
        abort_unless($repository->directory && ! in_array($repository->status, ['queued', 'running', 'draft']), 422, 'Espera a que termine Git.');

        return $repository->directory;
    }

    public function quick(Site $site, int $repositoryId, string $action, array $payload = []): array
    {
        $directory = $this->directory($site, $repositoryId);

        return app(DomainGit::class)->quick($site, $action, ['directory' => $directory, ...$payload]);
    }
}
