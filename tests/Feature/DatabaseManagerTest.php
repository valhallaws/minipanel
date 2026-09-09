<?php

namespace Tests\Feature;

use App\Livewire\DatabaseManager;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\SiteDatabaseUser;
use App\Models\User;
use App\Services\DatabaseStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_database_and_user_validates_both_before_saving(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{}'));
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()]);
        $component->call('newDatabase')->set('databaseName', 'combined')->set('databaseUserMode', 'new')
            ->set('username', 'combined_user')->set('password', 'short')->call('createDatabase')->assertHasErrors('password');
        $this->assertDatabaseCount('site_databases', 0);
        $this->assertDatabaseCount('site_database_users', 0);
        Process::assertNothingRan();

        $component->set('password', 'Abcdef12345!')->set('access', 'any')->call('createDatabase')->assertHasErrors('confirmRemote');
        $this->assertDatabaseCount('site_databases', 0);
        $component->set('confirmRemote', true)->call('createDatabase')->assertHasNoErrors()->assertSet('modal', null);
        $user = SiteDatabaseUser::query()->sole();
        $this->assertSame(SiteDatabase::query()->sole()->id, $user->site_database_id);
        $this->assertFalse($user->all_databases);
        $this->assertSame('any', $user->access);
    }

    public function test_database_uses_spanish_collation_by_default_and_sends_it_to_mariadb(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{}'));

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->call('newDatabase')
            ->set('databaseName', 'catalogo')
            ->call('createDatabase')
            ->assertHasNoErrors();

        $database = SiteDatabase::query()->sole();
        $this->assertSame('utf8mb4_spanish2_ci', $database->collation);
        Process::assertRan(fn ($process) => (json_decode($process->input, true)['databases'][0]['collation'] ?? null) === 'utf8mb4_spanish2_ci');
    }

    public function test_existing_user_keeps_previous_database_access_and_credentials(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{}'));
        $site = $this->site();
        $old = $site->databases()->create(['name' => 'original']);
        $user = $site->databaseUsers()->create(['name' => 'reader', 'password' => 'Abcdef12345!', 'site_database_id' => $old->id, 'all_databases' => false, 'permission' => 'read']);
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site]);
        $component->call('newDatabase')->set('databaseName', 'additional')->set('databaseUserMode', 'existing')
            ->set('existingDatabaseUserId', $user->id)->call('createDatabase')->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasAccessTo($old));
        $this->assertTrue($user->hasAccessTo($site->databases()->where('name', 'additional')->sole()));
        $this->assertSame('Abcdef12345!', $user->password);
        Process::assertRan(fn ($process) => (json_decode($process->input, true)['users'][0]['databases'] ?? []) === ['original', 'additional']);

        $component->call('editUser', $user->id)->call('saveUser')->assertHasNoErrors();
        $this->assertSame(1, $user->additionalDatabases()->count());
        $component->call('editUser', $user->id)->set('databaseId', 0)->call('saveUser')->assertHasNoErrors();
        $this->assertSame(0, $user->additionalDatabases()->count());
    }

    public function test_combined_creation_rejects_a_user_from_another_domain(): void
    {
        Process::fake();
        $user = $this->site('other.example.com')->databaseUsers()->create(['name' => 'outsider', 'password' => 'Abcdef12345!']);
        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->call('newDatabase')->set('databaseName', 'private')->set('databaseUserMode', 'existing')
            ->set('existingDatabaseUserId', $user->id)->call('createDatabase')->assertHasErrors('existingDatabaseUserId');
        $this->assertDatabaseCount('site_databases', 0);
        Process::assertNothingRan();
    }

    public function test_inactive_domain_keeps_creation_modal_open_with_explanation(): void
    {
        Process::fake();
        $site = $this->site();
        $site->update(['status' => 'provisioning']);
        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->call('newDatabase')->set('databaseName', 'app')->call('createDatabase')
            ->assertHasErrors('database')->assertSet('modal', 'database')->assertSee('Completa su aprovisionamiento');
        $this->assertDatabaseCount('site_databases', 0);
        Process::assertNothingRan();
    }

    public function test_statistics_are_scoped_cached_and_refreshed_without_executing_sql_mutations(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('{"app":{"bytes":2097152,"tables":7},"private":{"bytes":1,"tables":1}}'));
        $site = $this->site();
        $site->databases()->create(['name' => 'app']);
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site]);
        $component->call('refreshStatistics')->assertSet('statistics', ['app' => ['bytes' => 2097152, 'tables' => 7]])->assertSee('2.00 MB');
        $component->call('refreshStatistics');
        Process::assertRanTimes(fn ($process) => (json_decode($process->input, true)['statistics'] ?? false) === true, 1);
        DatabaseStatistics::forget($site);
        $component->call('refreshStatistics');
        Process::assertRanTimes(fn ($process) => (json_decode($process->input, true)['statistics'] ?? false) === true, 2);
    }

    public function test_statistics_failure_is_not_displayed_as_an_empty_database(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'secret', exitCode: 1));
        $site = $this->site();
        $site->databases()->create(['name' => 'app']);
        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->call('refreshStatistics')->assertSet('statistics', [])->assertSee('No se pudieron actualizar')->assertDontSee('secret');
    }

    public function test_database_modal_stays_open_on_error_and_closes_after_success(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->assertSet('modal', null)->call('newDatabase')->assertSet('modal', 'database')
            ->set('databaseName', 'bad%')->call('createDatabase')->assertHasErrors('databaseName')->assertSet('modal', 'database')
            ->set('databaseName', 'app')->call('createDatabase')->assertHasNoErrors()->assertSet('modal', null);

        $this->assertDatabaseHas('site_databases', ['name' => 'app', 'status' => 'active']);
    }

    public function test_cancel_user_modal_discards_password_without_creating_user(): void
    {
        Process::fake();

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->call('newUser')->assertSet('modal', 'user')->set('password', 'UnsavedPassword123!')
            ->call('closeModal')->assertSet('modal', null)->assertSet('password', '');

        $this->assertDatabaseCount('site_database_users', 0);
        Process::assertNothingRan();
    }

    public function test_database_user_password_requires_twelve_characters_on_create_and_edit(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()]);

        $component->call('newUser')->set('username', 'app')->set('password', 'Abcdef1234!')
            ->call('saveUser')->assertHasErrors('password')->assertSet('modal', 'user');
        $this->assertDatabaseCount('site_database_users', 0);
        Process::assertNothingRan();

        $component->set('password', 'Abcdef12345!')->call('saveUser')->assertHasNoErrors();
        $user = SiteDatabaseUser::query()->sole();
        $this->assertSame('Abcdef12345!', $user->password);

        $component->call('editUser', $user->id)->set('password', 'Changed123!')
            ->call('saveUser')->assertHasErrors('password');
        $this->assertSame('Abcdef12345!', $user->fresh()->password);
        $component->set('password', 'Changed1234!')->call('saveUser')->assertHasNoErrors();
        $this->assertSame('Changed1234!', $user->fresh()->password);

        $component->call('editUser', $user->id)->call('saveUser')->assertHasNoErrors();
        $this->assertSame('Changed1234!', $user->fresh()->password);
    }

    public function test_database_page_requires_authentication(): void
    {
        $this->get(route('sites.databases', $this->site()))->assertRedirect(route('login'));
    }

    public function test_database_is_created_with_exact_chosen_name(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->set('databaseName', 'Orion_prod')->call('createDatabase')->assertHasNoErrors();

        $database = SiteDatabase::query()->sole();
        $this->assertSame('Orion_prod', $database->name);
        $this->assertSame('active', $database->status);
        Process::assertRan(fn ($process) => $process->command[2] === 'database-sync');
    }

    public function test_local_execution_disabled_does_not_touch_mariadb(): void
    {
        config()->set('minipanel.execution_enabled', false);
        Process::fake();

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('databaseName', 'app')->call('createDatabase')->assertHasErrors('database');

        $this->assertSame('pending', SiteDatabase::query()->sole()->status);
        Process::assertNothingRan();
    }

    public function test_user_password_is_encrypted_and_hidden_and_all_databases_include_future_ones(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));
        $site = $this->site();
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site]);
        $component->set('username', 'app')->set('password', 'LongUniquePassword123!')->call('saveUser')->assertHasNoErrors();

        $user = SiteDatabaseUser::query()->sole();
        $this->assertSame('app', $user->name);
        $this->assertNotSame('LongUniquePassword123!', $user->getRawOriginal('password'));
        $this->assertArrayNotHasKey('password', $user->toArray());

        $component->set('databaseName', 'next')->call('createDatabase')->assertHasNoErrors();

        Process::assertRan(fn ($process) => (json_decode($process->input, true)['users'][0]['databases'] ?? []) === ['next']);
    }

    public function test_duplicate_names_across_domains_are_rejected_without_running_agent(): void
    {
        Process::fake();
        $other = $this->site('other.example.com');
        $other->databases()->create(['name' => 'orion']);
        $other->databaseUsers()->create(['name' => 'orion_user', 'password' => 'LongUniquePassword123!']);

        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()]);
        $component->set('databaseName', 'orion')->call('createDatabase')->assertHasErrors('databaseName');
        $component->set('username', 'orion_user')->set('password', 'LongUniquePassword123!')->call('saveUser')->assertHasErrors('username');

        $this->assertDatabaseCount('site_databases', 1);
        $this->assertDatabaseCount('site_database_users', 1);
        Process::assertNothingRan();
    }

    public function test_editing_preserves_existing_full_user_name(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));
        $site = $this->site();
        $user = $site->databaseUsers()->create(['name' => 'dc76327ce3e211a31xlegacy', 'password' => 'LongUniquePassword123!']);

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $site])
            ->call('editUser', $user->id)->assertSet('username', 'dc76327ce3e211a31xlegacy')
            ->set('permission', 'read')->call('saveUser')->assertHasNoErrors();

        $this->assertSame('dc76327ce3e211a31xlegacy', $user->fresh()->name);
        $this->assertSame('read', $user->fresh()->permission);
    }

    public function test_names_with_sql_characters_are_rejected(): void
    {
        Process::fake();
        $component = Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()]);
        $component->set('databaseName', 'bad%name')->call('createDatabase')->assertHasErrors('databaseName');
        $component->set('username', "bad'name")->set('password', 'LongUniquePassword123!')->call('saveUser')->assertHasErrors('username');

        $this->assertDatabaseCount('site_databases', 0);
        $this->assertDatabaseCount('site_database_users', 0);
        Process::assertNothingRan();
    }

    public function test_cannot_assign_another_domains_database(): void
    {
        Process::fake();
        $other = $this->site('other.example.com');
        $database = $other->databases()->create(['name' => 'otherdb']);

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('username', 'app')->set('password', 'LongUniquePassword123!')
            ->set('databaseId', $database->id)->call('saveUser')->assertHasErrors('databaseId');

        $this->assertDatabaseCount('site_database_users', 0);
        Process::assertNothingRan();
    }

    public function test_remote_access_requires_explicit_confirmation_and_valid_ip(): void
    {
        Process::fake();

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('username', 'app')->set('password', 'LongUniquePassword123!')
            ->set('access', 'ip')->set('remoteIp', 'invalid')->call('saveUser')
            ->assertHasErrors(['remoteIp', 'confirmRemote']);

        Process::assertNothingRan();
    }

    public function test_any_ip_is_allowed_when_confirmed(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Applied'));

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('username', 'app')->set('password', 'LongUniquePassword123!')
            ->set('access', 'any')->set('confirmRemote', true)->call('saveUser')->assertHasNoErrors();

        $this->assertSame('any', SiteDatabaseUser::query()->sole()->access);
        Process::assertRan(fn ($process) => json_decode($process->input, true)['users'][0]['access'] === 'any');
    }

    public function test_failed_agent_keeps_pending_state_and_does_not_expose_output(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'secret-value', exitCode: 1));

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('databaseName', 'app')->call('createDatabase')
            ->assertHasErrors('database')->assertDontSee('secret-value');

        $this->assertSame('pending', SiteDatabase::query()->sole()->status);
    }

    public function test_failed_agent_shows_a_safe_database_failure_reason(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'Database administration failed [existing-database].', exitCode: 1));

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('databaseName', 'app')
            ->call('createDatabase')
            ->assertHasErrors('database')
            ->assertSee('Ya existe una base de datos que no administra Freyja.');
    }

    public function test_database_application_retries_once_after_an_initial_agent_failure(): void
    {
        config()->set('minipanel.execution_enabled', true);
        $attempts = 0;
        Process::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Process::result(errorOutput: 'private agent output', exitCode: 1)
                : Process::result('Applied');
        });

        Livewire::actingAs(User::factory()->create())->test(DatabaseManager::class, ['site' => $this->site()])
            ->set('databaseName', 'first_resource')
            ->call('createDatabase')
            ->assertHasNoErrors();

        $this->assertSame('active', SiteDatabase::query()->sole()->status);
        Process::assertRanTimes(fn ($process) => $process->command[2] === 'database-sync' && ! (json_decode($process->input, true)['statistics'] ?? false), 2);
    }

    private function site(string $domain = 'example.com'): Site
    {
        return Site::create(['name' => 'Example', 'domain' => $domain, 'path' => '/var/www/'.$domain, 'runtime' => 'static', 'status' => 'active', 'php_version' => '8.3']);
    }
}
