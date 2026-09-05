<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use PragmaRX\Google2FALaravel\Google2FA;

class TwoFactorController extends Controller
{
    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->session()->has('two_factor_pending_user_id')
            ? view('auth.two-factor')
            : redirect()->route('login');
    }

    public function verify(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = User::find($request->session()->get('two_factor_pending_user_id'));

        if (! $user || ! $this->validCode($user, $data['code'])) {
            return back()->withErrors(['code' => 'El código no es válido.']);
        }

        Auth::login($user, (bool) $request->session()->pull('two_factor_remember'));
        $request->session()->forget('two_factor_pending_user_id');
        $request->session()->regenerate();
        $audit->record('auth.two_factor_verified', $user);

        return redirect()->intended('/');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['two_factor_pending_user_id', 'two_factor_remember']);

        return redirect()->route('login');
    }

    public function settings(Request $request): View
    {
        $secret = $request->session()->get('two_factor_setup_secret');
        $qrCode = $secret ? $this->google2fa()->getQRCodeInline(config('app.name'), $request->user()->email, $secret) : null;

        return view('auth.security', [
            'secret' => $secret,
            'qrCode' => $qrCode,
            'recoveryCodes' => $request->session()->pull('two_factor_recovery_plain', []),
            'activity' => AuditLog::where('user_id', $request->user()->id)->latest('created_at')->take(8)->get(),
            'passkeys' => $request->user()->passkeys()->latest('created_at')->get(),
            'canManagePasskeys' => (int) $request->session()->get('auth.password_confirmed_at', 0) >= now()->subSeconds((int) config('auth.password_timeout', 10800))->timestamp,
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        $request->session()->put('two_factor_setup_secret', $this->google2fa()->generateSecretKey());

        return redirect()->route('security.settings');
    }

    public function confirm(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = $request->session()->get('two_factor_setup_secret');

        if (! $secret || ! $this->google2fa()->verifyKey($secret, $data['code'], 1)) {
            return back()->withErrors(['code' => 'El código no coincide. Intenta el siguiente código del autenticador.']);
        }

        $plainCodes = collect(range(1, 8))->map(fn () => strtoupper(str()->random(4).'-'.str()->random(4)))->all();
        $request->user()->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => collect($plainCodes)->map(fn (string $code) => Hash::make($code))->all(),
            'two_factor_confirmed_at' => now(),
        ]);
        $request->session()->forget('two_factor_setup_secret');
        $request->session()->put('two_factor_recovery_plain', $plainCodes);
        $audit->record('auth.two_factor_enabled', $request->user());

        return redirect()->route('security.settings');
    }

    public function disable(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password) || ! $this->validCode($user, $data['code'])) {
            return back()->withErrors(['code' => 'Contraseña o código inválido.']);
        }

        $user->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        $audit->record('auth.two_factor_disabled', $user);

        return redirect()->route('security.settings')->with('notice', '2FA desactivado.');
    }

    private function validCode(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) && $this->google2fa()->verifyKey($user->two_factor_secret, $code, 1)) {
            return true;
        }

        foreach ($user->two_factor_recovery_codes ?? [] as $index => $hash) {
            if (Hash::check(strtoupper($code), $hash)) {
                $codes = $user->two_factor_recovery_codes;
                unset($codes[$index]);
                $user->update(['two_factor_recovery_codes' => array_values($codes)]);

                return true;
            }
        }

        return false;
    }

    private function google2fa(): Google2FA
    {
        return app('pragmarx.google2fa')->setQrCodeBackend('svg');
    }
}
