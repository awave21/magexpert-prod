<?php

namespace App\Sender\Jobs;

use App\Sender\Models\ContactList;
use App\Sender\Services\SubscriptionConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Рассылает письма подтверждения подписки новым подписчикам базы порциями (SubscriptionConfirmationService::PER_RUN):
 * после порции ставит своё продолжение через минуту, а если упёрлась в лимит — через 10 минут.
 * Каждый подписчик получает одно письмо: повторный запуск задачи никому не отправит второе.
 */
class SendSubscriptionConfirmationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAUSE_SECONDS = 60;

    public const LIMITED_PAUSE_SECONDS = 600;

    public int $tries = 3;

    public int $timeout = 80;

    public int $uniqueFor = 900;

    public function __construct(public int $listId) {}

    public function uniqueId(): string
    {
        return (string) $this->listId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sender-confirm-'.$this->listId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(SubscriptionConfirmationService $confirmations): void
    {
        $list = ContactList::query()->find($this->listId);

        if ($list === null || $list->isSynced()) {
            return;
        }

        $result = $confirmations->sendBatch($list);

        if ($result['more']) {
            self::dispatch($this->listId)
                ->onQueue($this->queue ?? config('sender.queue'))
                ->delay(now()->addSeconds($result['limited'] ? self::LIMITED_PAUSE_SECONDS : self::PAUSE_SECONDS));
        }
    }
}
