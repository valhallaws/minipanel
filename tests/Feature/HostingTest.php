<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\BackupManager;
use App\Livewire\DatabaseManager;
use App\Livewire\SiteManager;
use App\Livewire\SslManager;
use App\Models\Deployment;
use App\Models\ServerSetting;
use App\Models\Site;
use App\Models\SiteRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class HostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosting_requires_authentication(): void
    {
        $this->get(route('sites.show', $this->site()))->assertRedirect(route('login'));
    }

    public function test_ssl_page_shows_real_coverage_and_uses_signed_in_email(): void
    {
        Queue::fake([RunDeployment::class]);
        $site = $this->site();
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->actingAs($user)->get(route('sites.ssl', $site))->assertOk()
            ->assertSee('Certificado para example.com')->assertSee('*.example.com')->assertSee('owner@example.com')
            ->assertSee('Requiere configurar DNS del servidor');
        Livewire::actingAs($user)->test(SslManager::class, ['site' => $site])
            ->set('acceptCertificateTerms', true)->call('issueCertificate')->assertHasNoErrors();
        $operation = Deployment::sole();
        $this->assertSame('issue-ssl', $operation->action);
        $this->assertSame('owner@example.com', $operation->parameters['certificate_email']);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_only_databases_open_inside_the_expanded_domain(): void
    {
        config()->set('minipanel.execution_enabled', false);
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site, 'embedded' => true])
            ->assertSee('href="'.route('sites.files', $site).'"', false)
            ->assertSee('href="'.route('sites.git', $site).'"', false)
            ->assertSee('href="'.route('sites.ssl', $site).'"', false)
            ->assertSee('href="'.route('sites.backups', $site).'"', false)
            ->call('openDatabases')->assertSet('databasesOpened', true)->assertSeeLivewire(DatabaseManager::class);
    }

    public function test_a_completed_backup_can_be_selected_for_a_confirmed_restore(): void
    {
        Queue::fake([RunDeployment::class]);
        $site = $this->site();
        $backup = Deployment::create([
            'site_id' => $site->id,
            'user_id' => User::factory()->create()->id,
            'action' => 'backup',
            'status' => 'finished',
            'output' => '/var/backups/minipanel/example.com-20260907-120000.tar.gz',
            'finished_at' => now(),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('sites.backups', $site))->assertOk()->assertSee('Copias disponibles');
        Livewire::actingAs($user)->test(BackupManager::class, ['site' => $site])
            ->call('selectBackupForRestore', $backup->id)->assertSet('restoreBackupDeploymentId', $backup->id)
            ->set('restoreConfirmation', 'RESTAURAR example.com')->call('restoreBackup')->assertHasNoErrors();

        $restore = Deployment::query()->where('action', 'restore-backup')->sole();
        $this->assertSame(['RESTAURAR example.com', '/var/backups/minipanel/example.com-20260907-120000.tar.gz'], $restore->parameters['arguments']);
        Queue::assertPushed(RunDeployment::class, fn ($job) => $job->deploymentId === $restore->id);
    }

    public function test_hosting_queues_changes_without_prematurely_applying_them(): void
    {
        Queue::fake([RunDeployment::class]);
        $site = $this->site();

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->set('documentRoot', 'app/public')->set('hostingPhpVersion', '8.4')
            ->set('hostingUploadLimit', '1g')->set('hostingMemoryLimit', '512M')->set('hostingExecutionTimeout', '300')
            ->call('saveHosting')->assertHasNoErrors();

        $operation = Deployment::query()->sole();
        $this->assertSame('configure-hosting', $operation->action);
        $this->assertSame(['app/public', '8.4', '1g', '512M', '300', '22'], $operation->parameters['arguments']);
        $this->assertNull($site->fresh()->document_root);
        $this->assertSame('8.3', $site->fresh()->php_version);
        Queue::assertPushed(RunDeployment::class, fn ($job) => $job->deploymentId === $operation->id);
    }

    public function test_hosting_uses_php_versions_detected_from_the_server_catalog(): void
    {
        config()->set('minipanel.execution_enabled', true);
        cache()->forget('server-php-versions');
        Queue::fake([RunDeployment::class]);
        Process::fake(fn () => Process::result("8.5\tdisponible\n8.4\tinstalado\n"));

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $this->site()])
            ->set('hostingPhpVersion', '8.5')
            ->call('saveHosting')
            ->assertHasNoErrors();

        $operation = Deployment::query()->sole();
        $this->assertSame('8.5', $operation->parameters['arguments'][1]);
    }

    public function test_hosting_uses_node_versions_detected_from_the_server_catalog(): void
    {
        config()->set('minipanel.execution_enabled', true);
        cache()->forget('server-node-versions');
        Queue::fake([RunDeployment::class]);
        Process::fake(fn ($process) => in_array('server-node-versions', $process->command, true)
            ? Process::result("25\tdisponible\n24\tinstalado\n")
            : Process::result("8.4\tinstalado\n"));

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $this->site()])
            ->set('hostingNodeVersion', '25')
            ->call('saveHosting')
            ->assertHasNoErrors();

        $operation = Deployment::query()->sole();
        $this->assertSame('25', $operation->parameters['arguments'][5]);
    }

    public function test_site_summary_renders_scoped_disk_and_monthly_traffic_statistics(): void
    {
        config()->set('minipanel.execution_enabled', true);
        cache()->forget('site-statistics-1');
        $site = $this->site();
        cache()->forget('site-statistics-'.$site->id);
        ServerSetting::create([
            'public_ip' => '159.54.145.184',
            'db_username' => 'minipanel',
            'db_password' => 'secret',
        ]);
        Process::fake(fn ($process) => in_array('site-statistics', $process->command, true)
            ? Process::result('{"disk_bytes":1610612736,"traffic_bytes":1048576,"traffic_available":true}')
            : Process::result(''));

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->call('loadSiteStatistics')
            ->assertSee('1.5 GB')
            ->assertSee('1 MB')
            ->assertSee('159.54.145.184')
            ->assertSee('/var/www/example.com');
    }

    public function test_site_summary_uses_the_repository_branch_and_commit_metadata(): void
    {
        $site = $this->site();
        SiteRepository::factory()->for($site)->create([
            'status' => 'ready',
            'branch' => 'release',
            'commits' => [['hash' => 'a1b2c3d4e5f6', 'subject' => 'Release']],
            'last_push_at' => now(),
            'synced_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->assertSee('Rama release')
            ->assertSee('a1b2c3d4')
            ->assertSee('Último push');
    }

    public function test_site_summary_formats_last_push_in_the_server_timezone(): void
    {
        $site = $this->site();
        ServerSetting::create([
            'timezone' => 'America/Tijuana',
            'db_username' => 'minipanel',
            'db_password' => 'secret',
        ]);
        SiteRepository::factory()->for($site)->create([
            'status' => 'ready',
            'last_push_at' => CarbonImmutable::parse('2026-09-09 18:00:00', 'UTC'),
        ]);

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->assertSee('09/09/2026 11:00');
    }

    public function test_unsafe_document_roots_are_rejected(): void
    {
        Queue::fake();
        $component = Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $this->site()]);

        foreach (['../other', '/etc', 'app/../other', '.git', 'public;exit'] as $path) {
            $component->set('documentRoot', $path)->call('saveHosting')->assertHasErrors('documentRoot');
        }

        $this->assertDatabaseCount('deployments', 0);
        Queue::assertNothingPushed();
    }

    public function test_successful_agent_applies_hosting_settings(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Hosting configured'));
        $operation = $this->operation('configure-hosting', ['arguments' => ['public', '8.4']]);

        (new RunDeployment($operation->id))->handle();

        $this->assertSame('public', $operation->site->fresh()->document_root);
        $this->assertSame('8.4', $operation->site->fresh()->php_version);
        $this->assertSame('22', $operation->site->fresh()->node_version);
        $this->assertSame(['upload_limit' => '2g', 'memory_limit' => '256M', 'execution_timeout' => 120], $operation->site->fresh()->hosting_limits);
        $this->assertSame('finished', $operation->fresh()->status);
    }

    public function test_successful_provisioning_records_the_first_deploy_time(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Site provisioned'));
        $operation = $this->operation('provision', []);

        (new RunDeployment($operation->id))->handle();

        $this->assertSame('active', $operation->site->fresh()->status);
        $this->assertNotNull($operation->site->fresh()->last_deployed_at);
    }

    public function test_hosting_change_allows_time_for_php_installation(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Hosting configured'));
        $operation = $this->operation('configure-hosting', ['arguments' => ['public', '8.4']]);

        (new RunDeployment($operation->id))->handle();

        Process::assertRan(fn ($process) => $process->timeout === 1800
            && in_array('8.4', $process->command, true));
    }

    public function test_successful_agent_persists_the_selected_hosting_limits(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Hosting configured'));
        $operation = $this->operation('configure-hosting', ['arguments' => ['public', '8.4', '1g', '512M', '300']]);

        (new RunDeployment($operation->id))->handle();

        $this->assertSame([
            'upload_limit' => '1g',
            'memory_limit' => '512M',
            'execution_timeout' => 300,
        ], $operation->site->fresh()->hosting_limits);
    }

    public function test_failed_agent_preserves_hosting_settings(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result(errorOutput: 'Nginx failed', exitCode: 1));
        $operation = $this->operation('configure-hosting', ['arguments' => ['public', '8.4']]);

        (new RunDeployment($operation->id))->handle();

        $this->assertNull($operation->site->fresh()->document_root);
        $this->assertSame('8.3', $operation->site->fresh()->php_version);
        $this->assertSame('failed', $operation->fresh()->status);
    }

    public function test_certificate_requires_terms(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $this->site()])
            ->call('issueCertificate')->assertHasErrors(['acceptCertificateTerms']);

        $this->assertDatabaseCount('deployments', 0);
        Queue::assertNothingPushed();
    }

    public function test_certificate_uses_authenticated_users_email(): void
    {
        Queue::fake([RunDeployment::class]);
        $user = User::factory()->create(['email' => 'owner@example.com']);
        Livewire::actingAs($user)->test(SiteManager::class, ['site' => $this->site()])
            ->assertDontSee('wire:model="certificateEmail"', false)
            ->set('acceptCertificateTerms', true)->call('issueCertificate')->assertHasNoErrors();
        $operation = Deployment::sole();
        $this->assertSame('owner@example.com', $operation->parameters['certificate_email']);
        $this->assertSame($user->id, $operation->user_id);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_certificate_renewal_is_confirmed_after_success(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('MINIPANEL_RENEWAL_ENABLED'));
        $operation = $this->operation('issue-ssl', ['certificate_email' => 'admin@example.com']);

        (new RunDeployment($operation->id))->handle();

        $this->assertTrue($operation->site->fresh()->ssl_enabled);
        $this->assertTrue($operation->site->fresh()->ssl_auto_renew);
    }

    private function site(): Site
    {
        return Site::create(['name' => 'Example', 'domain' => 'example.com', 'path' => '/var/www/example.com', 'runtime' => 'static', 'php_version' => '8.3', 'status' => 'active']);
    }

    public function test_successful_suspension_and_reactivation_preserve_domain_data(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Operation complete'));
        $operation = $this->operation('suspend-site', []);

        (new RunDeployment($operation->id))->handle();

        $this->assertSame('suspended', $operation->site->fresh()->status);
        $this->assertSame('/var/www/example.com', $operation->site->fresh()->path);
        $resume = Deployment::create(['site_id' => $operation->site_id, 'user_id' => $operation->user_id, 'action' => 'resume-site', 'status' => 'queued']);
        (new RunDeployment($resume->id))->handle();
        $this->assertSame('active', $operation->site->fresh()->status);
    }

    public function test_queued_hosting_change_is_blocked_if_domain_was_suspended(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake();
        $operation = $this->operation('configure-hosting', ['arguments' => ['public', '8.4']]);
        $operation->site->update(['status' => 'suspended']);

        (new RunDeployment($operation->id))->handle();

        $this->assertSame('blocked', $operation->fresh()->status);
        Process::assertNothingRan();
    }

    public function test_hosting_reports_an_inactive_domain_without_a_generic_http_error(): void
    {
        Queue::fake();
        $site = $this->site();
        $site->update(['status' => 'suspended']);

        Livewire::actingAs(User::factory()->create())->test(SiteManager::class, ['site' => $site])
            ->call('saveHosting')
            ->assertHasErrors('hosting');

        Queue::assertNothingPushed();
    }

    private function operation(string $action, array $parameters): Deployment
    {
        return Deployment::create(['site_id' => $this->site()->id, 'user_id' => User::factory()->create()->id, 'action' => $action, 'parameters' => $parameters, 'status' => 'queued']);
    }
}
