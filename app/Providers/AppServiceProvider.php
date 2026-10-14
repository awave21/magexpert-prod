<?php

namespace App\Providers;

use App\Jobs\SyncUserToBitrix24;
use App\Jobs\SyncUserToSender;
use App\Models\User;
use App\Services\Assistant\DemoLibraryAssistant;
use App\Services\Assistant\LibraryAssistant;
use App\Services\Assistant\RagLibraryAssistant;
use App\Services\Assistant\UnavailableLibraryAssistant;
use App\Services\Sender\ContactSyncClient;
use App\Services\Sender\LogContactSyncClient;
use App\Services\Sender\SenderApi;
use App\Services\Sender\SenderClient;
use App\Services\Sender\SubscriptionClient;
use App\Services\VkIdProvider;
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
        // Sender: письма, согласие на рассылку и базы только через публичный API (docs/sender-api.openapi.yaml)
        $this->app->bind(SenderClient::class, SenderApi::class);
        $this->app->bind(SubscriptionClient::class, SenderApi::class);
        // без адреса и ключа API база пользователей лишь пишет в лог, ничего не отправляя
        $this->app->bind(ContactSyncClient::class, fn () => SenderApi::configured()
            ? app(SenderApi::class)
            : app(LogContactSyncClient::class));

        // ИИ-помощник кабинета: rag — поиск по документам библиотеки, demo — тестовые ответы (не в продакшене)
        $this->app->bind(LibraryAssistant::class, fn (): LibraryAssistant => match (true) {
            config('services.assistant.driver') === 'rag' => new RagLibraryAssistant,
            config('services.assistant.driver') === 'demo' && ! $this->app->isProduction() => new DemoLibraryAssistant,
            default => new UnavailableLibraryAssistant,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('yandex', \SocialiteProviders\Yandex\Provider::class);
            $event->extendSocialite('vkid', VkIdProvider::class);
        });

        $this->syncNewsletterConsentWithSender();
        $this->syncUsersListWithSender();
    }

    /**
     * Галочка «Письма о мероприятиях» и отписка в Sender — одно и то же согласие: переключение галочки
     * сообщается в Sender через API, а отписка по ссылке из письма приходит вебхуком (SenderWebhookController).
     */
    private function syncNewsletterConsentWithSender(): void
    {
        User::updated(function (User $user): void {
            if (! $user->wasChanged('newsletter_consent') || blank($user->email)) {
                return;
            }

            // человек сам снял согласие: запоминаем, чтобы регистрация на мероприятие его не вернула
            // запросом, а не save(): иначе у модели сбросится wasChanged() для остальных обработчиков
            $withdrawnAt = $user->newsletter_consent ? null : now();

            if ($user->newsletter_consent ? $user->newsletter_withdrawn_at !== null : true) {
                User::query()->whereKey($user->getKey())->toBase()->update(['newsletter_withdrawn_at' => $withdrawnAt]);
                $user->setAttribute('newsletter_withdrawn_at', $withdrawnAt)->syncOriginalAttribute('newsletter_withdrawn_at');
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

        // изменения профиля уходят и в Bitrix24: туда, где контакт уже есть
        User::updated(function (User $user): void {
            if (SyncUserToBitrix24::enabled() && $user->wasChanged(SyncUserToBitrix24::trackedFields()) && SyncUserToSender::syncable($user)) {
                $previousEmail = $user->wasChanged('email') ? $user->getOriginal('email') : null;

                SyncUserToBitrix24::dispatch($user->id, filled($previousEmail) ? $previousEmail : null);
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
        if (! config('services.sender.users_sync')) {
            return false;
        }

        if (SenderApi::configured()) {
            return true;
        }

        if (! $this->usersSyncWarned) {
            $this->usersSyncWarned = true;
            Log::warning('Sender: SENDER_USERS_SYNC включён, но SENDER_API_URL или SENDER_API_KEY не указаны — пользователи в базу не передаются');
        }

        return false;
    }
}
