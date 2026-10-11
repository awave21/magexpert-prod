<?php

namespace App\Sender\Console;

use App\Sender\Models\Message;
use Illuminate\Console\Command;

/**
 * Удаляет письма старше срока хранения вместе с их событиями (доставка, открытия, клики).
 * Срок задаётся в SENDER_RETENTION_DAYS и указан в политике обработки персональных данных.
 */
class PruneCommand extends Command
{
    protected $signature = 'sender:prune';

    protected $description = 'Удалить письма и события старше срока хранения';

    public function handle(): int
    {
        $days = (int) config('sender.retention_days');
        $deleted = 0;

        do {
            $ids = Message::query()->where('created_at', '<', now()->subDays($days))->limit(1000)->pluck('id');
            $deleted += Message::query()->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());

        $this->info("Удалено писем старше {$days} дней: {$deleted}");

        return self::SUCCESS;
    }
}
