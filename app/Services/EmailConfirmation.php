<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Ссылка подтверждения email: подписанная, действует 7 дней и открывается без входа на сайт.
 * Привязана к текущему адресу — после смены email старая ссылка перестаёт работать.
 */
class EmailConfirmation
{
    public const TTL_DAYS = 7;

    public function url(User $user): string
    {
        return URL::temporarySignedRoute('email.confirm', now()->addDays(self::TTL_DAYS), [
            'id' => $user->getKey(),
            'hash' => $this->hash($user),
        ]);
    }

    public function matches(User $user, string $hash): bool
    {
        return hash_equals($this->hash($user), $hash);
    }

    private function hash(User $user): string
    {
        return sha1(strtolower((string) $user->email));
    }
}
