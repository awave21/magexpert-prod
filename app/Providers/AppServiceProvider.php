<?php

namespace App\Providers;

use App\Jobs\SyncUserToSender;
use App\Models\User;
use App\Sender\Client\HttpContactSyncClient;
use App\Sender\Contracts\SubscriptionClient;
use App\Sender\Events\RecipientUnsubscribed;
use App\Sender\Services\SubscriberSources;
use App\Services\NewsletterSubscribers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    private bool $usersSyncWarned = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // база «Пользователи сайта» в Sender
        $this->app->tag([NewsletterSubscribers::class], SubscriberSources::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('yandex', \SocialiteProviders\Yandex\Provider::class);
            if (class_exists(\SocialiteProviders\VKID\Provider::class)) {
                $event->extendSocialite('vkid', \SocialiteProviders\VKID\Provider::class);
            }
        });

        $this->syncNewsletterConsentWithSender();
        $this->syncUsersListWithSender();
    }

    /**
     * Галочка «Письма о мероприятиях» и отписка в Sender — одно и то же согласие:
     * отписка по ссылке из письма снимает галочку, а переключение галочки сообщается в Sender.
     */
    private function syncNewsletterConsentWithSender(): void
    {
        Event::listen(function (RecipientUnsubscribed $event): void {
            if ($event->organizationSlug !== config('sender.client.organization')) {
                return;
            }

            $users = User::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($event->email)])
                ->where('newsletter_consent', true);
            $userIds = (clone $users)->pluck('id');

            // массовое обновление не вызывает событий модели: согласие не отправляется обратно в Sender
            $users->update(['newsletter_consent' => false]);

            // база «Пользователи MagExpert» узнаёт об отписке через API; отписку по ссылке это не вызывает повторно
            if ($this->usersSyncEnabled()) {
                $userIds->each(fn (int $id) => SyncUserToSender::dispatch($id, mb_strtolower($event->email)));
            }
        });

        User::updated(function (User $user): void {
            if (! $user->wasChanged('newsletter_consent') || blank($user->email)) {
                return;
            }

            try {
                app(SubscriptionClient::class)->setSubscription($user->email, (bool) $user->newsletter_consent);
            } catch (Throwable $exception) {
                Log::warning('Sender: не удалось передать согласие на рассылку', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
            }
        });
    }

    /**
     * База «Пользователи MagExpert» в Sender: регистрация (любая — форма, Яндекс, ВК, админка, API),
     * изменение адреса, имени, полей профиля или галочки рассылки и удаление пользователя передаются в Sender
     * через очередь и открытый API. Включается SENDER_USERS_SYNC=true.
     */
    private function syncUsersListWithSender(): void
    {
        User::created(function (User $user): void {
            if ($this->usersSyncEnabled()) {
                SyncUserToSender::dispatch($user->id, $user->email);
            }
        });

        User::updated(function (User $user): void {
            if (! $user->wasChanged(SyncUserToSender::trackedFields()) || ! $this->usersSyncEnabled()) {
                return;
            }

            $previousEmail = $user->wasChanged('email') ? $user->getOriginal('email') : null;

            SyncUserToSender::dispatch($user->id, $user->email, filled($previousEmail) ? $previousEmail : null);
        });

        User::deleted(function (User $user): void {
            if (filled($user->email) && $this->usersSyncEnabled()) {
                SyncUserToSender::dispatch($user->id, $user->email);
            }
        });
    }

    /**
     * Синхронизация включена (SENDER_USERS_SYNC=true) и адрес с ключом API Sender указаны. Без них задачи
     * только копились бы в failed_jobs: они не ставятся, а в лог один раз за процесс пишется предупреждение.
     */
    private function usersSyncEnabled(): bool
    {
        if (! config('sender.client.users_sync')) {
            return false;
        }

        if (HttpContactSyncClient::configured()) {
            return true;
        }

        if (! $this->usersSyncWarned) {
            $this->usersSyncWarned = true;
            Log::warning('Sender: SENDER_USERS_SYNC включён, но SENDER_API_URL или SENDER_API_KEY не указаны — пользователи в базу не передаются');
        }

        return false;
    }
}
