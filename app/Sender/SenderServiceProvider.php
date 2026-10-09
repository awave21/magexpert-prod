<?php

namespace App\Sender;

use App\Sender\Client\HttpSenderClient;
use App\Sender\Client\LocalSenderClient;
use App\Sender\Console\DkimExportCommand;
use App\Sender\Console\DkimImportCommand;
use App\Sender\Console\DomainCommand;
use App\Sender\Console\InstallDefaultsCommand;
use App\Sender\Console\UserCreateCommand;
use App\Sender\Contracts\SenderClient;
use App\Sender\Dns\DnsLookup;
use App\Sender\Dns\PhpDnsLookup;
use App\Sender\Transport\LaravelMailTransport;
use App\Sender\Transport\Transport;
use Illuminate\Support\ServiceProvider;

/**
 * Провайдер модуля рассылок (Sender).
 *
 * Модуль использует отдельное подключение к БД "sender".
 * Его миграции применяются явно:
 * php artisan migrate --database=sender --path=app/Sender/Database/Migrations
 */
class SenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'sender');

        $this->app->bind(DnsLookup::class, PhpDnsLookup::class);
        $this->app->bind(Transport::class, LaravelMailTransport::class);
        $this->app->bind(SenderClient::class, fn () => config('sender.client.driver') === 'http'
            ? app(HttpSenderClient::class)
            : app(LocalSenderClient::class));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallDefaultsCommand::class, DomainCommand::class, DkimExportCommand::class, DkimImportCommand::class, UserCreateCommand::class]);
        }
    }
}
