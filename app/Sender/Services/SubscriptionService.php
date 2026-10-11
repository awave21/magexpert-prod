<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use Illuminate\Support\Str;

/**
 * Подписка адреса на рассылки организации. Отписка действует на все базы, в том числе загруженные позже:
 * адрес попадает в блок-лист с причиной unsubscribe, который запрещает рассылки, но не служебные письма.
 */
class SubscriptionService
{
    public function unsubscribe(Organization $organization, string $email): void
    {
        $email = Str::lower(trim($email));

        Contact::query()
            ->where('organization_id', $organization->id)
            ->where('email', $email)
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now()]);

        Suppression::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'email' => $email],
            ['reason' => Suppression::REASON_UNSUBSCRIBE],
        );
    }

    /**
     * Снова согласился на рассылку (например, включил галочку на сайте). Блокировки из-за отказов и жалоб остаются.
     */
    public function resubscribe(Organization $organization, string $email): void
    {
        $email = Str::lower(trim($email));

        Suppression::query()
            ->where('organization_id', $organization->id)
            ->where('email', $email)
            ->where('reason', Suppression::REASON_UNSUBSCRIBE)
            ->delete();

        Contact::query()
            ->where('organization_id', $organization->id)
            ->where('email', $email)
            ->whereNotNull('unsubscribed_at')
            ->update(['unsubscribed_at' => null]);
    }
}
