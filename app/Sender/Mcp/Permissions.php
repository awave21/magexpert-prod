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

    public function __construct(private readonly ?string $role) {}

    public function canEditContent(): bool
    {
        return in_array($this->role ?? 'admin', self::CONTENT_ROLES, true);
    }

    public function role(): string
    {
        return $this->role ?? 'admin';
    }
}
