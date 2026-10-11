<?php

namespace App\Sender\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Получатель отписался по ссылке из письма рассылки. Сайт снимает у пользователя согласие на рассылку.
 */
class RecipientUnsubscribed
{
    use Dispatchable;

    public function __construct(public string $organizationSlug, public string $email) {}
}
