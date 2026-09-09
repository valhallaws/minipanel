<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PanelUpdateWebhookTest extends TestCase
{
    public function test_queues_an_update_for_a_signed_push_to_the_configured_branch(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake(fn () => Process::result('Update started.'));

        $response = $this->sendWebhook(['ref' => 'refs/heads/main']);

        $response->assertAccepted()->assertJson(['queued' => true]);
        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-panel-update-start']);
    }

    public function test_returns_401_when_the_signature_is_invalid(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake();

        $response = $this->sendWebhook(['ref' => 'refs/heads/main'], 'sha256=invalid');

        $response->assertUnauthorized();
        Process::assertNothingRan();
    }

    public function test_ignores_a_signed_push_for_a_different_branch(): void
    {
        config()->set('minipanel.execution_enabled', true);
        Process::fake();

        $response = $this->sendWebhook(['ref' => 'refs/heads/experiment']);

        $response->assertNoContent();
        Process::assertNothingRan();
    }

    public function test_queues_a_signed_form_encoded_github_push(): void
    {
        config()->set('minipanel.execution_enabled', true);
        config()->set('minipanel.panel_update_webhook_secret', 'panel-update-webhook-secret-for-tests');
        Process::fake(fn () => Process::result('Update started.'));
        $content = 'payload='.rawurlencode('{"ref":"refs/heads/main"}');

        $response = $this->call('POST', route('webhooks.panel-update'), [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, 'panel-update-webhook-secret-for-tests'),
        ], $content);

        $response->assertAccepted()->assertJson(['queued' => true]);
        Process::assertRan(fn ($process) => $process->command === ['sudo', '/usr/local/bin/minipanel-agent', 'server-panel-update-start']);
    }

    public function test_returns_503_when_execution_is_disabled(): void
    {
        config()->set('minipanel.execution_enabled', false);
        Process::fake();

        $response = $this->sendWebhook(['ref' => 'refs/heads/main']);

        $response->assertServiceUnavailable();
        Process::assertNothingRan();
    }

    public function test_accepts_a_signed_github_ping_without_starting_an_update(): void
    {
        Process::fake();

        $response = $this->sendWebhook(['zen' => 'Keep it logically awesome.'], event: 'ping');

        $response->assertNoContent();
        Process::assertNothingRan();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendWebhook(array $payload, ?string $signature = null, string $event = 'push'): TestResponse
    {
        config()->set('minipanel.panel_update_webhook_secret', 'panel-update-webhook-secret-for-tests');
        config()->set('minipanel.panel_update_branch', 'main');
        $content = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('webhooks.panel-update'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $content, 'panel-update-webhook-secret-for-tests'),
        ], $content);
    }
}
