<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOutboxMail;
use App\Models\MailOutbox;
use Illuminate\Console\Command;

/**
 * Подбирает письма из исходящих, которые не ушли: доставка не была поставлена в очередь или задача потерялась.
 * Запускается по расписанию; с --failed возвращает в очередь и отклонённые письма (после исправления шаблона).
 */
class MailOutboxRetry extends Command
{
    protected $signature = 'mail:outbox-retry {--failed : Вернуть в очередь и письма со статусом failed} {--id=* : Только эти записи}';

    protected $description = 'Повторить доставку писем из исходящих';

    public function handle(): int
    {
        $query = MailOutbox::query()->where(function ($q): void {
            $q->where('status', MailOutbox::STATUS_PENDING)
                ->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                // свежие записи ещё обрабатывает очередь
                ->where('created_at', '<=', now()->subMinutes(2));

            if ($this->option('failed')) {
                $q->orWhere('status', MailOutbox::STATUS_FAILED);
            }
        });

        if ($this->option('id') !== []) {
            $query->whereIn('id', array_map('intval', $this->option('id')));
        }

        $count = 0;

        $query->orderBy('id')->limit(500)->get()->each(function (MailOutbox $entry) use (&$count): void {
            // повторно отправлять можно только то, что не ушло: Idempotency-Key защищает от дубля, если письмо всё же ушло
            $entry->forceFill(['status' => MailOutbox::STATUS_PENDING, 'next_attempt_at' => null])->save();
            DeliverOutboxMail::dispatch($entry->id);
            $count++;
        });

        $this->info("Поставлено в очередь: {$count}");

        return self::SUCCESS;
    }
}
