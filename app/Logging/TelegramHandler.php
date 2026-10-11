<?php

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\TelegramBotHandler;
use RuntimeException;

/**
 * Отправка в Telegram через HTTP-клиент Laravel с коротким таймаутом: штатный TelegramBotHandler шлёт curl
 * без таймаута, и подвисший Telegram подвесил бы запрос сайта. Текст без разметки, не длиннее 4000 символов.
 */
class TelegramHandler extends TelegramBotHandler
{
    public const MAX_LENGTH = 4000;

    public function __construct(private readonly string $token, private readonly string $chatId, mixed $level)
    {
        parent::__construct($token, $chatId, $level, true, null, true);
    }

    protected function send(string $message): void
    {
        $response = Http::timeout(3)->connectTimeout(3)->asForm()->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => mb_strimwidth($message, 0, self::MAX_LENGTH, '…'),
            'disable_web_page_preview' => 'true',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram ответил '.$response->status());
        }
    }
}
