<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;

class SiteAlertNotifier
{
    public function synchronizeHealth(Site $site): void
    {
        $lastTwoChecks = $site->healthChecks()->latest('checked_at')->limit(2)->get();
        $isUnavailable = $lastTwoChecks->count() === 2 && $lastTwoChecks->every(fn ($check): bool => $check->status === 'failed');
        $this->synchronize(
            $site,
            'availability',
            $isUnavailable,
            '⚠️ Freyja: '.$site->resourceDomain().' no respondió en dos comprobaciones consecutivas.'
        );

        $expiration = $site->ssl_expires_at;
        $isExpiring = $site->ssl_enabled && $expiration instanceof Carbon && $expiration->lessThanOrEqualTo(now()->addDays(21));
        $this->synchronize(
            $site,
            'certificate',
            $isExpiring,
            '⚠️ Freyja: el certificado SSL de '.$site->resourceDomain().' vence el '.($expiration?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—').'.'
        );
    }

    public function synchronizeBackup(Site $site, bool $succeeded, string $output = ''): void
    {
        $this->synchronize(
            $site,
            'backup',
            ! $succeeded,
            '⚠️ Freyja: falló el respaldo de '.$site->resourceDomain().($output !== '' ? ' · '.str($output)->squish()->limit(180)->toString() : '.')
        );
    }

    private function synchronize(Site $site, string $type, bool $isActive, string $message): void
    {
        $alert = $site->alerts()->firstOrNew(['type' => $type]);

        if (! $isActive) {
            if ($alert->exists && $alert->status === 'active') {
                $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
            }

            return;
        }

        if ($alert->exists && $alert->status === 'active') {
            $alert->update(['message' => $message]);

            return;
        }

        $alert->fill([
            'status' => 'active',
            'message' => $message,
            'resolved_at' => null,
            'last_notified_at' => null,
        ]);
        $alert->save();

        if (app(TelegramNotifier::class)->send($message)) {
            $alert->update(['last_notified_at' => now()]);
        }
    }
}
