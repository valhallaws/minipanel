<?php

namespace App\Services;

use App\Models\ServerSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public function configured(): bool
    {
        $settings = ServerSetting::query()->first();

        return (bool) ($settings?->telegram_enabled && filled($settings->telegram_bot_token) && filled($settings->telegram_chat_id));
    }

    public function send(string $message): bool
    {
        $settings = ServerSetting::query()->first();
        if (! $settings?->telegram_enabled || blank($settings->telegram_bot_token) || blank($settings->telegram_chat_id)) {
            return false;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->post('https://api.telegram.org/bot'.$settings->telegram_bot_token.'/sendMessage', [
                    'chat_id' => $settings->telegram_chat_id,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Telegram notification failed.', ['status' => $response->status()]);
        } catch (\Throwable $exception) {
            Log::warning('Telegram notification could not be delivered.', ['exception' => $exception::class]);
        }

        return false;
    }
}
