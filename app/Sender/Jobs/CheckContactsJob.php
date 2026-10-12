<?php

namespace App\Sender\Jobs;

use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Services\EmailChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Проверяет ещё не проверенные адреса базы порциями: не больше PER_JOB адресов и MAX_SECONDS секунд за раз,
 * потом ставит своё продолжение. Короткие задачи укладываются в retry_after очереди (90 с) и не держат воркер.
 * Адреса, по которым DNS не ответил, остаются непроверенными и проверяются при следующем запуске.
 */
class CheckContactsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PER_JOB = 1000;

    public const MAX_SECONDS = 60;

    public int $tries = 3;

    public int $timeout = 80;

    public int $uniqueFor = 600;

    public function __construct(public int $listId, public int $afterContactId = 0) {}

    /**
     * Продолжение со своим курсором не мешает новой проверке той же базы с начала.
     */
    public function uniqueId(): string
    {
        return $this->listId.':'.$this->afterContactId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sender-check-'.$this->listId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(EmailChecker $checker): void
    {
        $list = ContactList::query()->find($this->listId);

        if ($list === null) {
            return;
        }

        $started = microtime(true);
        $lastId = $this->afterContactId;
        $checked = 0;
        $unblocked = false;

        foreach ($list->contacts()->whereNull('checked_at')->where('id', '>', $this->afterContactId)->orderBy('id')->lazyById(100) as $contact) {
            $wasBlocked = $this->awaitsLetter($contact) && in_array($contact->check_status, EmailChecker::UNDELIVERABLE, true);
            $checker->checkContacts([$contact]);
            $unblocked = $unblocked || ($wasBlocked && ! in_array($contact->check_status, EmailChecker::UNDELIVERABLE, true));
            $lastId = $contact->id;
            $checked++;

            if ($checked >= self::PER_JOB || microtime(true) - $started >= self::MAX_SECONDS) {
                self::dispatch($this->listId, $lastId)->onQueue($this->queue ?? config('sender.queue'));
                break;
            }
        }

        // повторная проверка признала адрес, ждущий подтверждения, рабочим: ему уходит его единственное письмо
        if ($unblocked && ! $list->isSynced()) {
            SendSubscriptionConfirmationsJob::dispatch($list->id)->onQueue($this->queue ?? config('sender.queue'));
        }
    }

    private function awaitsLetter(Contact $contact): bool
    {
        return $contact->isPending() && $contact->confirmation_sent_at === null && $contact->unsubscribed_at === null;
    }
}
