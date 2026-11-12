<?php

namespace App\Providers;

use App\Services\VkIdProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class SocialAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('yandex', \SocialiteProviders\Yandex\Provider::class);
            $event->extendSocialite('vkid', VkIdProvider::class);
        });
    }
}
