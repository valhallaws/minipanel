<?php

namespace Tests\Feature;

use App\Livewire\ServerSetup;
use App\Models\ServerSetting;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use App\Models\User;
use App\Services\SiteAlertNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_settings_are_encrypted_and_can_be_tested(): void
    {
        $settings = $this->settings();
        $token = '123456:abcdefghijklmnopqrstuvwx';

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('selectServerSection', 'security')
            ->set('telegramEnabled', true)
            ->set('telegramChatId', '123456789')
            ->set('telegramBotToken', $token)
            ->call('saveTelegramSettings')
            ->assertHasNoErrors()
            ->assertSet('telegramTokenConfigured', true)
            ->assertSee('Telegram está configurado');

        $this->assertTrue($settings->fresh()->telegram_enabled);
        $this->assertSame('123456789', $settings->fresh()->telegram_chat_id);
        $this->assertSame($token, $settings->fresh()->telegram_bot_token);
        $this->assertNotSame($token, $this->app['db']->table('server_settings')->value('telegram_bot_token'));

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        Livewire::actingAs(User::factory()->create())->test(ServerSetup::class)
            ->call('sendTelegramTest')
            ->assertHasNoErrors();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bot'.$token.'/sendMessage'
            && $request['chat_id'] === '123456789'
            && $request['text'] === '✅ Freyja está listo para avisarte desde este VPS.');
    }

    public function test_availability_alert_is_deduplicated_and_reopens_after_recovery(): void
    {
        $this->settings(['telegram_enabled' => true, 'telegram_bot_token' => '123456:abcdefghijklmnopqrstuvwx', 'telegram_chat_id' => '123456789']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $site = $this->site();
        SiteHealthCheck::create(['site_id' => $site->id, 'status' => 'failed', 'checked_at' => now()->subMinutes(10)]);
        SiteHealthCheck::create(['site_id' => $site->id, 'status' => 'failed', 'checked_at' => now()]);

        $notifier = app(SiteAlertNotifier::class);
        $notifier->synchronizeHealth($site);
        $this->assertDatabaseHas('site_alerts', ['site_id' => $site->id, 'type' => 'availability', 'status' => 'active']);
        Http::assertSentCount(1);

        $notifier->synchronizeHealth($site);
        Http::assertSentCount(1);

        SiteHealthCheck::create(['site_id' => $site->id, 'status' => 'healthy', 'checked_at' => now()->addSecond()]);
        $notifier->synchronizeHealth($site);
        $this->assertDatabaseHas('site_alerts', ['site_id' => $site->id, 'type' => 'availability', 'status' => 'resolved']);

        SiteHealthCheck::create(['site_id' => $site->id, 'status' => 'failed', 'checked_at' => now()->addMinutes(1)]);
        SiteHealthCheck::create(['site_id' => $site->id, 'status' => 'failed', 'checked_at' => now()->addMinutes(2)]);
        $notifier->synchronizeHealth($site);
        Http::assertSentCount(2);
    }

    public function test_certificate_and_backup_alerts_are_sent_once_until_resolved(): void
    {
        $this->settings(['telegram_enabled' => true, 'telegram_bot_token' => '123456:abcdefghijklmnopqrstuvwx', 'telegram_chat_id' => '123456789']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $site = $this->site(['ssl_enabled' => true, 'ssl_expires_at' => now()->addDays(10)]);
        $notifier = app(SiteAlertNotifier::class);

        $notifier->synchronizeHealth($site);
        $notifier->synchronizeHealth($site);
        $notifier->synchronizeBackup($site, false, 'No space left on device.');
        $notifier->synchronizeBackup($site, false, 'No space left on device.');
        Http::assertSentCount(2);

        $notifier->synchronizeBackup($site, true);
        $this->assertDatabaseHas('site_alerts', ['site_id' => $site->id, 'type' => 'backup', 'status' => 'resolved']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function settings(array $attributes = []): ServerSetting
    {
        return ServerSetting::create([...[
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_username' => 'freyja',
            'db_password' => 'Password123!',
        ], ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Site
    {
        return Site::create([...[
            'name' => 'example.test',
            'domain' => 'example.test',
            'path' => '/var/www/example.test',
            'status' => 'active',
            'runtime' => 'static',
            'php_version' => '8.4',
            'document_root' => 'httpdocs',
        ], ...$attributes]);
    }
}
