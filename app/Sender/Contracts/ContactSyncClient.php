<?php

namespace App\Sender\Contracts;

use App\Sender\Client\SenderApiException;

/**
 * Работа с базами контактов Sender через его открытый API — так же, как это делал бы любой внешний сервис.
 * Сайт держит этим клиентом в актуальном виде базу «Пользователи MagExpert».
 *
 * Все методы бросают SenderApiException, если Sender ответил ошибкой или недоступен.
 */
interface ContactSyncClient
{
    /**
     * Находит базу по названию или создаёт её. Возвращает ID базы.
     *
     * @throws SenderApiException
     */
    public function ensureList(string $name, ?string $description = null): int;

    /**
     * Добавляет или обновляет контакт в базе. Возвращает статус контакта: subscribed, pending или unsubscribed.
     *
     * @param  array<string, scalar|null>  $data  дополнительные поля контакта (переменные письма)
     * @param  list<string>  $tags  теги: добавляются к уже сохранённым, ничего не затирают и подписку не меняют
     *
     * @throws SenderApiException
     */
    public function upsertContact(int $listId, string $email, ?string $name, array $data, bool $subscribed, array $tags = []): string;

    /**
     * Пакетно добавляет или обновляет до 1000 контактов.
     *
     * @param  list<array{email: string, name?: string|null, data?: array<string, scalar|null>, subscribed?: bool, tags?: list<string>}>  $contacts
     * @return array{created: int, updated: int, subscribed: int, pending: int, unsubscribed: int, invalid: list<array{index: int, email: string|null, errors: mixed}>}
     *
     * @throws SenderApiException
     */
    public function upsertContacts(int $listId, array $contacts): array;

    /**
     * Удаляет контакт из базы. Если контакта уже нет, ничего не происходит.
     *
     * @throws SenderApiException
     */
    public function deleteContact(int $listId, string $email): void;
}
