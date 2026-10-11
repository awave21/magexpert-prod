<?php

namespace App\Providers;

use App\Models\User;
use App\Sender\Contracts\SubscriptionClient;
use App\Sender\Events\RecipientUnsubscribed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

            User::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($event->email)])
                ->where('newsletter_consent', true)
                ->update(['newsletter_consent' => false]);
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
}
