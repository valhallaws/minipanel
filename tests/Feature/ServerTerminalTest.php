<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerTerminalTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_terminal_requires_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('server.terminal'))
            ->assertOk()
            ->assertSee('Confirmar y abrir consola root')
            ->assertDontSee('src="/server-terminal/"', false);

        $this->actingAs($user)->get(route('server.terminal.auth'))->assertForbidden();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('server.terminal'))
            ->assertOk()
            ->assertSee('src="/server-terminal/"', false);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('server.terminal.auth'))
            ->assertNoContent();
    }
}
