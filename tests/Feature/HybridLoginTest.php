<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HybridLoginTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['mfdzmirabal'])]
    #[TestWith(['MFDZMIRABAL'])]
    #[TestWith(['owner@example.com'])]
    #[TestWith([' OWNER@EXAMPLE.COM '])]
    public function test_login_accepts_username_or_email(string $login): void
    {
        $user = User::factory()->create(['username' => 'mfdzmirabal', 'email' => 'owner@example.com']);
        $this->post('/login', ['login' => $login, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_email_form_remains_compatible(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    #[TestWith(['mfdzmirabal'])]
    #[TestWith(['owner@example.com'])]
    #[TestWith(["' OR 1=1 --"])]
    public function test_invalid_credentials_do_not_authenticate(string $login): void
    {
        User::factory()->create(['username' => 'mfdzmirabal', 'email' => 'owner@example.com']);
        $this->post('/login', ['login' => $login, 'password' => 'wrong'])->assertSessionHasErrors(['login' => 'Credenciales inválidas.']);
        $this->assertGuest();
    }

    #[TestWith(['mfdzmirabal'])]
    #[TestWith(['owner@example.com'])]
    public function test_both_identifiers_still_require_two_factor(string $login): void
    {
        $user = User::factory()->create(['username' => 'mfdzmirabal', 'email' => 'owner@example.com', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [Hash::make('ABCD-EFGH')]]);
        $this->post('/login', ['login' => $login, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'))->assertSessionHas('two_factor_pending_user_id', $user->id);
        $this->assertGuest();
        $this->post('/two-factor', ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor', ['code' => 'ABCD-EFGH'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_alternating_identifiers_shares_login_rate_limit(): void
    {
        User::factory()->create(['username' => 'mfdzmirabal', 'email' => 'owner@example.com']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['login' => $attempt % 2 ? 'mfdzmirabal' : 'owner@example.com', 'password' => 'wrong'])->assertSessionHasErrors('login');
        }
        $this->post('/login', ['login' => 'MFDZMIRABAL', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_username_assignment_preserves_existing_passkey_identity(): void
    {
        $user = User::factory()->create();
        $handle = $user->getPasskeyUserHandle();
        $account = $user->getPasskeyUsername();
        $passkey = $user->passkeys()->create(['name' => 'Existing device', 'credential_id' => 'existing-credential', 'credential' => ['test' => 'unchanged']]);
        $user->update(['username' => 'mfdzmirabal']);
        $this->assertSame($handle, $user->fresh()->getPasskeyUserHandle());
        $this->assertSame($account, $user->fresh()->getPasskeyUsername());
        $this->assertSame($user->id, $passkey->fresh()->user->id);
        $this->assertSame(['test' => 'unchanged'], $passkey->fresh()->credential);
    }

    public function test_login_form_supports_password_manager_and_passkey_autofill(): void
    {
        $this->get('/login')->assertOk()->assertSee('Usuario o correo')->assertSee('autocomplete="username webauthn"', false)->assertSee('data-passkey-login', false);
    }
}
