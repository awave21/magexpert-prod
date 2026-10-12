<?php

namespace App\Logging;

use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * DeduplicationHandler копит записи до конца процесса. Воркер очереди живёт час, поэтому буфер
 * сбрасывается после каждой записи: ошибка приходит сразу, а повтор в пределах окна по-прежнему отсекается.
 */
class ImmediateDeduplicationHandler extends DeduplicationHandler
{
    public function __construct(HandlerInterface $handler, ?string $deduplicationStore = null, int|string|Level $deduplicationLevel = Level::Error, int $time = 60)
    {
        parent::__construct($handler, $deduplicationStore, $deduplicationLevel, $time);

        // буфер сбрасывается после каждой записи, поэтому сброс при завершении процесса не нужен
        $this->initialized = true;
    }

    public function handle(LogRecord $record): bool
    {
        $result = parent::handle($record);

        try {
            $this->flush();
        } finally {
            // если Telegram не ответил, запись не должна остаться в буфере и уйти позже, при уничтожении обработчика
            $this->clear();
        }

        return $result;
    }
}
