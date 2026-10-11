<?php

namespace App\Sender\Contracts;

interface SubscriptionClient
{
    /**
     * Сообщает, согласен ли адрес получать рассылки. Служебные письма отписка не затрагивает.
     */
    public function setSubscription(string $email, bool $subscribed): void;
}
