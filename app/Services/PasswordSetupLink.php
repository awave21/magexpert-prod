<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Ссылка «Задать пароль» для пользователя, которого создали за него (регистрация на мероприятие, API).
 * Пароль нигде не генерируется и не отправляется: человек сам придумывает его по одноразовой ссылке.
 */
class PasswordSetupLink
{
    public function url(User $user): string
    {
        $token = Password::broker()->createToken($user);

        return url(route('password.reset', ['token' => $token, 'email' => $user->email], false));
    }

    public function expiresInMinutes(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    }

    /**
     * Пароль для аккаунта, в который человек пока не входил по паролю: случайный и никому не известный.
     */
    public static function unusablePassword(): string
    {
        return \Illuminate\Support\Str::random(64);
    }
}
