<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function confirmPassword(Request $request): View
    {
        if ($request->query('intended') === route('server.terminal')) {
            $request->session()->put('url.intended', route('server.terminal'));
        }

        return view('auth.confirm-password');
    }

    public function storeConfirmedPassword(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            return back()->withErrors(['password' => 'La contraseña actual no es correcta.']);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('security.settings'));
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $credentials = $request->validate(['login' => ['required', 'string', 'max:254'], 'password' => ['required', 'string']]);
        $user = User::findForLogin($credentials['login']);
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $audit->record('auth.login_failed', null, [], $user?->id);

            return back()->withErrors(['login' => 'Credenciales inválidas.'])->onlyInput('login');
        }
        if ($user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put('two_factor_pending_user_id', $user->id);
            $request->session()->put('two_factor_remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $audit->record('auth.login', $user);

        return redirect()->intended('/');
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', Auth::user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
