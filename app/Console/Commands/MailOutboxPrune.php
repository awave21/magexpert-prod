<?php

namespace App\Console\Commands;

use App\Models\MailOutbox;
use Illuminate\Console\Command;

/**
 * Удаляет из исходящих отправленные письма старше срока хранения (services.sender.outbox_days).
 * Неотправленные и отклонённые остаются: по ним нужно разбираться.
 */
class MailOutboxPrune extends Command
{
    protected $signature = 'mail:outbox-prune';

    protected $description = 'Удалить из исходящих старые отправленные письма';

    public function handle(): int
    {
        $days = (int) config('services.sender.outbox_days', 30);
        $deleted = MailOutbox::query()
            ->where('status', MailOutbox::STATUS_SENT)
            ->where('sent_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Удалено отправленных писем старше {$days} дней: {$deleted}");

        return self::SUCCESS;
    }
}
