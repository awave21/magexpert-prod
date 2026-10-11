<?php

namespace App\Sender\Contracts;

/**
 * Откуда берутся подписчики синхронизируемой базы: приложение, в котором работает Sender, отдаёт тех,
 * кто согласился на рассылку. База повторяет этот список при каждом обновлении и перед запуском рассылки.
 */
interface SubscriberSource
{
    /**
     * Ключ источника, хранится в базе: например, site.
     */
    public function key(): string;

    /**
     * Название для админки: «Пользователи сайта».
     */
    public function label(): string;

    /**
     * Slug организации Sender, которой доступен источник.
     */
    public function organization(): string;

    /**
     * Подписчики: email, имя и дополнительные поля, доступные в письме как переменные.
     *
     * @return iterable<array{email: string, name: string|null, data: array<string, string>}>
     */
    public function subscribers(): iterable;
}
