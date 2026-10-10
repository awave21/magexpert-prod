<?php

namespace App\Sender\Jobs;

use App\Sender\Models\ContactList;
use App\Sender\Services\EmailChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Проверяет ещё не проверенные адреса базы. Адреса, по которым DNS не ответил, остаются непроверенными
 * и проверяются при следующем запуске.
 */
class CheckContactsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public int $listId) {}

    public function uniqueId(): string
    {
        return (string) $this->listId;
    }

    public function handle(EmailChecker $checker): void
    {
        $list = ContactList::query()->find($this->listId);

        $list?->contacts()->whereNull('checked_at')->chunkById(500, fn ($contacts) => $checker->checkContacts($contacts));
    }
}
