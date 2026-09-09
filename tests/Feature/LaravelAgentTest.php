<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class LaravelAgentTest extends TestCase
{
    private function agent(): \MiniPanelGit
    {
        require_once base_path('ops-agent/minipanel-git.php');
        Storage::fake('local');
        Storage::disk('local')->put('site/httpdocs/artisan', '<?php');
        Storage::disk('local')->put('site/httpdocs/composer.json', '{"require":{"laravel/framework":"*"}}');
        Storage::disk('local')->put('site/httpdocs/.env', "APP_KEY=keep-me\n");

        return new \MiniPanelGit(Storage::disk('local')->path('site'), '8.3');
    }

    public function test_env_save_is_private_and_preserves_submitted_key(): void
    {
        $agent = $this->agent();
        $env = $agent->execute('laravel-env-read', ['directory' => 'httpdocs']);
        $result = $agent->execute('laravel-env-write', ['directory' => 'httpdocs', 'hash' => $env['hash'], 'contents' => "APP_KEY=keep-me\nDB_DATABASE=erp\n"]);
        $this->assertTrue($result['saved']);
        $this->assertSame("APP_KEY=keep-me\nDB_DATABASE=erp\n", Storage::disk('local')->get('site/httpdocs/.env'));
        $this->assertSame(0600, fileperms(Storage::disk('local')->path('site/httpdocs/.env')) & 0777);
    }

    public function test_concurrent_env_edit_is_not_overwritten(): void
    {
        $agent = $this->agent();
        $env = $agent->execute('laravel-env-read', ['directory' => 'httpdocs']);
        Storage::disk('local')->put('site/httpdocs/.env', 'Changed externally');
        try {
            $agent->execute('laravel-env-write', ['directory' => 'httpdocs', 'hash' => $env['hash'], 'contents' => 'Old editor']);
            $this->fail('Conflict should be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cambió', $exception->getMessage());
        }
        $this->assertSame('Changed externally', Storage::disk('local')->get('site/httpdocs/.env'));
    }

    public function test_env_symlink_is_refused(): void
    {
        $agent = $this->agent();
        Storage::disk('local')->delete('site/httpdocs/.env');
        Storage::disk('local')->put('outside', 'Secret');
        symlink(Storage::disk('local')->path('outside'), Storage::disk('local')->path('site/httpdocs/.env'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('enlazado');
        $agent->execute('laravel-env-read', ['directory' => 'httpdocs']);
    }

    public function test_shell_operator_is_rejected_before_execution(): void
    {
        $agent = $this->agent();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operadores de shell');
        $agent->execute('laravel-command', ['directory' => 'httpdocs', 'tool' => 'artisan', 'arguments' => ['list;', 'id']]);
    }

    public function test_non_laravel_folder_cannot_use_laravel_actions(): void
    {
        $agent = $this->agent();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se detectó Laravel');
        $agent->execute('laravel-env-read', ['directory' => '.']);
    }

    public function test_invalid_secret_is_refused_before_booting_application(): void
    {
        $agent = $this->agent();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Secret no válido');
        $agent->execute('laravel-maintenance', ['directory' => 'httpdocs', 'enabled' => true, 'secret' => '../secret']);
    }

    public function test_puppeteer_requires_a_node_project_before_running_npm(): void
    {
        $agent = $this->agent();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package.json');
        $agent->execute('laravel-puppeteer', ['directory' => 'httpdocs']);
    }
}
