<?php

namespace App\Policies;

use App\Models\User;

class RolePolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Определяет, может ли пользователь управлять ролями.
     */
    public function manageRoles(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    /**
     * Определяет, может ли пользователь просматривать список ролей.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'editor']);
    }

    /**
     * Определяет, может ли пользователь просматривать роль.
     */
    public function view(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'editor']);
    }

    /**
     * Определяет, может ли пользователь создавать роли.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Определяет, может ли пользователь обновлять роль.
     */
    public function update(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Определяет, может ли пользователь удалять роль.
     */
    public function delete(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
