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

    protected $description = 'Найти письма, зависшие при отправке, и отправить их заново или отметить отправленными';

    public function handle(MessageService $messages): int
    {
        $result = $messages->recoverStuck();

        $this->info("Отправлены ранее: {$result['sent']}, поставлены заново: {$result['requeued']}, с ошибкой: {$result['failed']}");

        return self::SUCCESS;
    }
}
