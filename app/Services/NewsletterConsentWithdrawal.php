<?php

namespace App\Services;

use App\Jobs\SyncUserToSender;
use App\Models\User;

/**
 * Человек отписался от рассылок (по ссылке из письма или пожаловался на спам): снимаем галочку
 * «Письма о мероприятиях». Один код для события внутри приложения и для вебхука Sender.
 */
class NewsletterConsentWithdrawal
{
    public function withdraw(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $users = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('newsletter_consent', true);
        $userIds = (clone $users)->pluck('id');

        // массовое обновление не вызывает событий модели: согласие не отправляется обратно в Sender
        $users->update(['newsletter_consent' => false, 'newsletter_withdrawn_at' => now()]);

        // база «Пользователи MagExpert» узнаёт об отписке через API; отписку по ссылке это не вызывает повторно
        if (config('sender.client.users_sync') && filled(config('sender.client.url')) && filled(config('sender.client.api_key'))) {
            $userIds->each(fn (int $id) => SyncUserToSender::dispatch($id, $email));
        }
    }
}
