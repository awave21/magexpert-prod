<?php

namespace App\Sender\Console;

use App\Sender\Services\MessageService;
use Illuminate\Console\Command;

/**
 * Разбирает письма, зависшие в статусе «отправляется» после падения воркера.
 */
class RecoverCommand extends Command
{
    protected $signature = 'sender:recover';

    protected $description = 'Найти письма, зависшие при отправке или потерявшие задачу в очереди, и разобрать их';

    public function handle(MessageService $messages): int
    {
        $result = $messages->recoverStuck();

        $this->info("Отправлены ранее: {$result['sent']}, поставлены заново: {$result['requeued']}, с ошибкой: {$result['failed']}, потерянные задачи восстановлены: {$result['redispatched']}");

        return self::SUCCESS;
    }
}
