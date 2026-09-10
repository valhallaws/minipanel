<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        View::composer('components.layouts.app', function ($view): void {
            $panelCommit = null;

            if (config('minipanel.execution_enabled')) {
                $panelCommit = Cache::remember('panel-commit-short', now()->addMinute(), function (): ?string {
                    try {
                        $result = Process::timeout(5)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-panel-update-status']);
                        if (! $result->successful()) {
                            return null;
                        }

                        $commit = collect(preg_split('/\R/', $result->output()))
                            ->first(fn (string $line): bool => str_starts_with($line, "Commit\t"));
                        $commit = is_string($commit) ? substr($commit, strlen("Commit\t")) : null;

                        return is_string($commit) && preg_match('/^[a-f0-9]{7,40}$/i', $commit) === 1 ? $commit : null;
                    } catch (\Throwable) {
                        return null;
                    }
                });
            }

            $view->with('panelCommit', $panelCommit);
        });

        RateLimiter::for('login', function (Request $request) {
            $login = $request->input('login', $request->input('email'));
            $login = is_string($login) && strlen($login) <= 254 ? strtolower(trim($login)) : '';
            $user = $login !== '' ? User::findForLogin($login) : null;

            return Limit::perMinute(5)->by(($user ? 'user:'.$user->id : 'login:'.hash('sha256', $login)).'|'.$request->ip());
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
