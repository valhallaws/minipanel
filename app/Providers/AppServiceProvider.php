<?php

namespace App\Providers;

use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip()
            );
        });

        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event): void {
            app(AuditLogger::class)->record('auth.passkey_registered', $event->passkey, ['name' => $event->passkey->name], $event->user->getAuthIdentifier());
        });

        Event::listen(PasskeyVerified::class, function (PasskeyVerified $event): void {
            app(AuditLogger::class)->record('auth.passkey_verified', $event->passkey, ['name' => $event->passkey->name], $event->user->getAuthIdentifier());
        });

        Event::listen(PasskeyDeleted::class, function (PasskeyDeleted $event): void {
            app(AuditLogger::class)->record('auth.passkey_deleted', $event->passkey, ['name' => $event->passkey->name], $event->user->getAuthIdentifier());
        });
    }
}
