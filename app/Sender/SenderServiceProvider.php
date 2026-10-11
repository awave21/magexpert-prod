<?php

namespace App\Sender;

use App\Sender\Client\HttpSenderClient;
use App\Sender\Client\LocalSenderClient;
use App\Sender\Console\AddressConfirmCommand;
use App\Sender\Console\CampaignsTickCommand;
use App\Sender\Console\DkimExportCommand;
use App\Sender\Console\DkimImportCommand;
use App\Sender\Console\DomainCommand;
use App\Sender\Console\InstallDefaultsCommand;
use App\Sender\Console\MailLogCommand;
use App\Sender\Console\PruneCommand;
use App\Sender\Console\TemplateImportCommand;
use App\Sender\Console\UserCreateCommand;
use App\Sender\Contracts\SenderClient;
use App\Sender\Contracts\SubscriptionClient;
use App\Sender\Dns\DnsLookup;
use App\Sender\Dns\PhpDnsLookup;
use App\Sender\Transport\LaravelMailTransport;
use App\Sender\Transport\Transport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(SubscriptionClient::class, fn () => config('sender.client.driver') === 'http'
            ? app(HttpSenderClient::class)
            : app(LocalSenderClient::class));
    }

    public function boot(): void
    {
        // API отправки: лимит на организацию (ключ), чтобы утёкший ключ не позволил отправить сколько угодно писем
        RateLimiter::for('sender-api', fn (Request $request): Limit => Limit::perMinute((int) config('sender.api_per_minute', 300))
            ->by('sender-api:'.($request->attributes->get('sender_organization')?->id ?? $request->ip())));

        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CampaignsTickCommand::class, InstallDefaultsCommand::class, DomainCommand::class, DkimExportCommand::class, DkimImportCommand::class, UserCreateCommand::class, AddressConfirmCommand::class, MailLogCommand::class, PruneCommand::class, TemplateImportCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('sender:prune')->dailyAt('03:30')->withoutOverlapping();
                $schedule->command('sender:campaigns-tick')->everyMinute()->withoutOverlapping();

                // статусы доставки из журнала Postfix, если он есть на этом сервере
                if (is_readable((string) config('sender.mail_log'))) {
                    $schedule->command('sender:mail-log')->everyMinute()->withoutOverlapping()->runInBackground();
                }
            });
        }
    }
}
