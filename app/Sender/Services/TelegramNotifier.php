<?php

namespace App\Sender\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Сообщения о проблемах Sender в Telegram: бот SENDER_TELEGRAM_BOT_TOKEN пишет в чат SENDER_TELEGRAM_CHAT_ID.
 */
class TelegramNotifier
{
    public function configured(): bool
    {
        return filled(config('sender.alerts.telegram_token')) && filled(config('sender.alerts.telegram_chat_id'));
    }

    public function send(string $text): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            return Http::timeout(10)
                ->post('https://api.telegram.org/bot'.config('sender.alerts.telegram_token').'/sendMessage', [
                    'chat_id' => config('sender.alerts.telegram_chat_id'),
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ])
                ->successful();
        } catch (Throwable $exception) {
            Log::warning('Sender: не удалось отправить алерт в Telegram', ['error' => $exception->getMessage()]);

            return false;
        }
    }
}
