<?php

namespace App\Providers;

use App\Sender\SenderServiceProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DeploymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $service = config('deployment.service', 'combined');

        if (! in_array($service, ['site', 'sender', 'combined'], true)) {
            throw new InvalidArgumentException('APP_SERVICE must be site, sender or combined.');
        }

        $this->app->register(SocialAuthServiceProvider::class);

        if ($service !== 'sender') {
            foreach ([AppServiceProvider::class, AuthServiceProvider::class, PaginationServiceProvider::class] as $provider) {
                $this->app->register($provider);
            }
        }

        if ($service !== 'site') {
            $this->app->register(SenderServiceProvider::class);
        }
    }
}
