<?php

namespace App\Services\Sender;

/**
 * Согласие адреса на рассылки через публичный API Sender (PUT /subscriptions).
 * Служебные письма отписка не затрагивает.
 */
interface SubscriptionClient
{
    /**
     * @throws SenderApiException
     */
    public function setSubscription(string $email, bool $subscribed): void;
}
