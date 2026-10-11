<?php

namespace App\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Канал логов `telegram`: ошибки сайта приходят в чат.
 *
 * Снаружи WhatFailureGroupHandler — сбой Telegram или файла дедупликации не роняет запрос и не пишет новых логов.
 * Внутри дедупликация: одна и та же ошибка за окно приходит один раз. В центре отправка с таймаутом.
 * Без токена или чата канал ничего не делает.
 */
class CreateTelegramLogger
{
    /**
     * @param  array{token?: string|null, chat_id?: string|null, level?: string|null, dedup_seconds?: int|null, dedup_store?: string|null}  $config
     */
    public function __invoke(array $config): Logger
    {
        $token = (string) ($config['token'] ?? '');
        $chatId = (string) ($config['chat_id'] ?? '');

        if ($token === '' || $chatId === '') {
            return new Logger('telegram', [new NullHandler]);
        }

        $level = Logger::toMonologLevel($config['level'] ?? 'error');

        $telegram = new TelegramHandler($token, $chatId, $level);
        $telegram->setFormatter(new TelegramFormatter);

        $deduplication = new ImmediateDeduplicationHandler(
            $telegram,
            $config['dedup_store'] ?? storage_path('logs/telegram-dedup.log'),
            $level,
            (int) ($config['dedup_seconds'] ?? 600),
        );
        // одинаковыми считаем ошибки одного класса из одного места, а не просто с одинаковым текстом
        $deduplication->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(message: TelegramFormatter::fingerprint($record)));

        return new Logger('telegram', [new WhatFailureGroupHandler([$deduplication])]);
    }
}
