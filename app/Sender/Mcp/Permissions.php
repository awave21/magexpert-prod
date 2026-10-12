<?php

namespace App\Sender\Mcp;

/**
 * Что ассистент может делать с ролью пользователя. Не зависит от констант модели:
 * неизвестная роль получает только просмотр, пустая роль — как у администратора (так же считает User::isAdmin).
 */
class Permissions
{
    /**
     * Роли, которым можно создавать и менять письма.
     */
    public const CONTENT_ROLES = ['owner', 'admin', 'editor', 'designer'];

    /**
     * Роли, которым можно менять базы, подписчиков и рассылки (как в админке: редактор и выше).
     */
    public const DATA_ROLES = ['owner', 'admin', 'editor'];

    public function __construct(private readonly ?string $role) {}

    public function canEditContent(): bool
    {
        return in_array($this->role ?? 'admin', self::CONTENT_ROLES, true);
    }

    public function canEditData(): bool
    {
        return in_array($this->role ?? 'admin', self::DATA_ROLES, true);
    }

    public function role(): string
    {
        return $this->role ?? 'admin';
    }
}
