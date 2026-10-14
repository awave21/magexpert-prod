<?php

namespace App\Sender;

use App\Sender\Client\HttpContactSyncClient;
use App\Sender\Client\HttpSenderClient;
use App\Sender\Client\ImapFeedbackMailbox;
use App\Sender\Client\LocalSenderClient;
use App\Sender\Client\LogContactSyncClient;
use App\Sender\Console\AddressConfirmCommand;
use App\Sender\Console\AssignOwnerCommand;
use App\Sender\Console\CampaignsTickCommand;
use App\Sender\Console\ComplaintCommand;
use App\Sender\Console\DkimExportCommand;
use App\Sender\Console\DkimImportCommand;
use App\Sender\Console\DomainCommand;
use App\Sender\Console\HealthCommand;
use App\Sender\Console\InstallDefaultsCommand;
use App\Sender\Console\MailLogCommand;
use App\Sender\Console\McpTokenCommand;
use App\Sender\Console\OrganizationCommand;
use App\Sender\Console\PruneCommand;
use App\Sender\Console\RecoverCommand;
use App\Sender\Console\ScrubSecretsCommand;
use App\Sender\Console\TemplateImportCommand;
use App\Sender\Console\UserCreateCommand;
use App\Sender\Console\WarmupCommand;
use App\Sender\Contracts\ContactSyncClient;
use App\Sender\Contracts\FeedbackMailbox;
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
        $this->app->bind(FeedbackMailbox::class, ImapFeedbackMailbox::class);
        $this->app->bind(SenderClient::class, fn () => config('sender.client.driver') === 'http'
            ? app(HttpSenderClient::class)
            : app(LocalSenderClient::class));
        $this->app->bind(SubscriptionClient::class, fn () => config('sender.client.driver') === 'http'
            ? app(HttpSenderClient::class)
            : app(LocalSenderClient::class));
        // базы контактов — только через HTTP API; драйвер local без адреса и ключа API лишь пишет в лог
        $this->app->bind(ContactSyncClient::class, fn () => config('sender.client.driver') === 'http' || HttpContactSyncClient::configured()
            ? app(HttpContactSyncClient::class)
            : app(LogContactSyncClient::class));
    }

    public function boot(): void
    {
        // API отправки: лимит на организацию (ключ), чтобы утёкший ключ не позволил отправить сколько угодно писем
        RateLimiter::for('sender-api', fn (Request $request): Limit => Limit::perMinute((int) config('sender.api_per_minute', 300))
            ->by('sender-api:'.($request->attributes->get('sender_organization')?->id ?? $request->ip())));

        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CampaignsTickCommand::class, ComplaintCommand::class, InstallDefaultsCommand::class, DomainCommand::class, HealthCommand::class, DkimExportCommand::class, DkimImportCommand::class, UserCreateCommand::class, AddressConfirmCommand::class, MailLogCommand::class, OrganizationCommand::class, PruneCommand::class, RecoverCommand::class, TemplateImportCommand::class, WarmupCommand::class, ScrubSecretsCommand::class]);
            $this->commands([McpTokenCommand::class, AssignOwnerCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('sender:prune')->dailyAt('03:30')->withoutOverlapping();
                $schedule->command('sender:campaigns-tick')->everyMinute()->withoutOverlapping();
                $schedule->command('sender:recover')->everyFiveMinutes()->withoutOverlapping();

                if (filled(config('sender.fbl.host'))) {
                    $schedule->command('sender:complaints --fetch')->everyTenMinutes()->withoutOverlapping();
                }

                if (filled(config('sender.alerts.telegram_token')) && filled(config('sender.alerts.telegram_chat_id'))) {
                    $schedule->command('sender:health')->everyFiveMinutes()->withoutOverlapping();
                }

                // статусы доставки из журнала Postfix, если он есть на этом сервере
                if (is_readable((string) config('sender.mail_log'))) {
                    $schedule->command('sender:mail-log')->everyMinute()->withoutOverlapping()->runInBackground();
                }
            });
        }
    }
}
