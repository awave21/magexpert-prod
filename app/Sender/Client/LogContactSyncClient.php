<?php

namespace App\Sender\Client;

use App\Sender\Contracts\ContactSyncClient;
use Illuminate\Support\Facades\Log;

/**
 * Заглушка для разработки: драйвер local без SENDER_API_URL и SENDER_API_KEY.
 * Ничего никуда не отправляет, только пишет в лог, что ушло бы в Sender: базу, количество и подписку, без адресов.
 * ID базы — 0: такой ID не запоминается, поэтому после настройки API база найдётся заново.
 */
class LogContactSyncClient implements ContactSyncClient
{
    public function ensureList(string $name, ?string $description = null): int
    {
        Log::info('Sender (заглушка): база контактов', ['name' => $name]);

        return 0;
    }

    public function upsertContact(int $listId, string $email, ?string $name, array $data, bool $subscribed, array $tags = []): string
    {
        Log::info('Sender (заглушка): контакт', ['list_id' => $listId, 'subscribed' => $subscribed]);

        return $subscribed ? 'subscribed' : 'unsubscribed';
    }

    public function upsertContacts(int $listId, array $contacts): array
    {
        Log::info('Sender (заглушка): пакет контактов', ['list_id' => $listId, 'count' => count($contacts)]);

        $subscribed = count(array_filter($contacts, fn (array $contact): bool => (bool) ($contact['subscribed'] ?? true)));

        return [
            'created' => 0,
            'updated' => 0,
            'subscribed' => $subscribed,
            'pending' => 0,
            'unsubscribed' => count($contacts) - $subscribed,
            'invalid' => [],
        ];
    }

    public function deleteContact(int $listId, string $email): void
    {
        Log::info('Sender (заглушка): удаление контакта', ['list_id' => $listId]);
    }
}
