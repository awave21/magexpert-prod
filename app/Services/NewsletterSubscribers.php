<?php

namespace App\Services;

use App\Models\User;
use App\Sender\Contracts\SubscriberSource;

/**
 * Пользователи сайта, включившие «Письма о мероприятиях»: источник базы подписчиков в Sender.
 * Специальность, город, организация и должность доступны в письме как переменные.
 */
class NewsletterSubscribers implements SubscriberSource
{
    public function key(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return 'Пользователи сайта с согласием на рассылку';
    }

    public function organization(): string
    {
        return (string) config('sender.client.organization');
    }

    public function subscribers(): iterable
    {
        $users = User::query()
            ->where('newsletter_consent', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->select(['id', 'email', 'first_name', 'last_name', 'specialization', 'city', 'company', 'position'])
            ->lazyById(1000);

        foreach ($users as $user) {
            yield [
                'email' => (string) $user->email,
                'name' => trim($user->first_name.' '.$user->last_name) ?: null,
                'data' => [
                    'specialization' => (string) $user->specialization,
                    'city' => (string) $user->city,
                    'company' => (string) $user->company,
                    'position' => (string) $user->position,
                ],
            ];
        }
    }
}
