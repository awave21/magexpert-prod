<?php

use App\Sender\Http\Controllers\Admin\ApiKeyController;
use App\Sender\Http\Controllers\Admin\AssetController;
use App\Sender\Http\Controllers\Admin\AuthController;
use App\Sender\Http\Controllers\Admin\CampaignController;
use App\Sender\Http\Controllers\Admin\ContactController;
use App\Sender\Http\Controllers\Admin\ContactListController;
use App\Sender\Http\Controllers\Admin\DomainController;
use App\Sender\Http\Controllers\Admin\McpTokenController;
use App\Sender\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Sender\Http\Controllers\Admin\OrganizationController;
use App\Sender\Http\Controllers\Admin\ProfileController;
use App\Sender\Http\Controllers\Admin\SenderAddressController;
use App\Sender\Http\Controllers\Admin\StatsController;
use App\Sender\Http\Controllers\Admin\SuppressionController;
use App\Sender\Http\Controllers\Admin\TemplateController;
use App\Sender\Http\Controllers\Admin\TemplateFolderController;
use App\Sender\Http\Controllers\Admin\UserController;
use App\Sender\Http\Controllers\Admin\VariableController;
use App\Sender\Http\Controllers\Admin\WebhookController;
use App\Sender\Http\Controllers\Api\ListContactController;
use App\Sender\Http\Controllers\Api\ListController;
use App\Sender\Http\Controllers\ConfirmSenderAddressController;
use App\Sender\Http\Controllers\ConfirmSubscriptionController;
use App\Sender\Http\Controllers\MessageController;
use App\Sender\Http\Controllers\OAuthController;
use App\Sender\Http\Controllers\SubscriptionController;
use App\Sender\Http\Controllers\TrackController;
use App\Sender\Http\Controllers\UnsubscribeController;
use App\Sender\Http\Middleware\ApiProblemResponse;
use App\Sender\Http\Middleware\AuthenticateApiKey;
use App\Sender\Http\Middleware\AuthenticateUser;
use App\Sender\Http\Middleware\AuthorizeRole;
use App\Sender\Http\Middleware\ForceJsonResponse;
use App\Sender\Http\Middleware\RecordAuditLog;
use App\Sender\Mcp\Http\AuthenticateMcpToken;
use App\Sender\Mcp\Http\McpController;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Facades\Route;

Route::prefix('api/sender/v1')->middleware('api')->group(function (): void {
    // публичный API: ответы всегда JSON, ошибки в едином формате application/problem+json
    Route::middleware([ForceJsonResponse::class, ApiProblemResponse::class, AuthenticateApiKey::class, 'throttle:sender-api'])->group(function (): void {
        Route::post('messages', [MessageController::class, 'store']);
        Route::get('messages/{uuid}', [MessageController::class, 'show']);
        Route::put('subscriptions', [SubscriptionController::class, 'update']);

        // базы и подписчики для внешних систем (например, сайта), которые держат базу в актуальном состоянии
        Route::name('sender.api.')->group(function (): void {
            Route::put('lists', [ListController::class, 'upsert'])->name('lists.upsert');
            Route::put('lists/{list}/contacts', [ListContactController::class, 'upsert'])->whereNumber('list')->name('contacts.upsert');
            Route::post('lists/{list}/contacts/batch', [ListContactController::class, 'batch'])->whereNumber('list')->name('contacts.batch');
            Route::get('lists/{list}/contacts/{email}', [ListContactController::class, 'show'])->whereNumber('list')->name('contacts.show');
            Route::delete('lists/{list}/contacts/{email}', [ListContactController::class, 'destroy'])->whereNumber('list')->name('contacts.destroy');
        });
    });

    // MCP-сервер для ИИ-ассистентов (Streamable HTTP без состояния), вход по личному ключу mxm_…
    Route::post('mcp', [McpController::class, 'handle'])->middleware(AuthenticateMcpToken::class)->name('sender.mcp');
    Route::match(['GET', 'DELETE'], 'mcp', [McpController::class, 'methodNotAllowed']);

    Route::prefix('admin')->name('sender.admin.')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,60')->name('register');
        Route::post('oauth/exchange', [AuthController::class, 'oauthExchange'])->middleware('throttle:20,1')->name('oauth.exchange');
        Route::get('oauth/providers', [AuthController::class, 'oauthProviders'])->name('oauth.providers');
        Route::get('oauth/pending', [AuthController::class, 'oauthPending'])->middleware('throttle:30,1')->name('oauth.pending');
        Route::post('oauth/register', [AuthController::class, 'oauthRegister'])->middleware('throttle:10,60')->name('oauth.register');

        Route::middleware([AuthenticateUser::class, AuthorizeRole::class, RecordAuditLog::class])->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::get('stats', StatsController::class)->name('stats');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:10,1')->name('profile.password');

            Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
            Route::put('organization', [OrganizationController::class, 'update'])->name('organization.update');
            Route::post('organization/transfer', [OrganizationController::class, 'transfer'])->middleware('throttle:10,1')->name('organization.transfer');

            Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
            Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
            Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
            Route::post('domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
            Route::get('domains/{domain}/dns-check', [DomainController::class, 'dnsCheck'])->middleware('throttle:30,1')->name('domains.dns-check');
            Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

            Route::get('template-folders', [TemplateFolderController::class, 'index'])->name('template-folders.index');
            Route::post('template-folders', [TemplateFolderController::class, 'store'])->name('template-folders.store');
            Route::put('template-folders/{folder}', [TemplateFolderController::class, 'update'])->name('template-folders.update');
            Route::delete('template-folders/{folder}', [TemplateFolderController::class, 'destroy'])->name('template-folders.destroy');

            Route::get('sender-addresses', [SenderAddressController::class, 'index'])->name('sender-addresses.index');
            Route::post('sender-addresses', [SenderAddressController::class, 'store'])->name('sender-addresses.store');
            Route::put('sender-addresses/{address}', [SenderAddressController::class, 'update'])->name('sender-addresses.update');
            Route::post('sender-addresses/{address}/resend', [SenderAddressController::class, 'resend'])->middleware('throttle:10,1')->name('sender-addresses.resend');
            Route::delete('sender-addresses/{address}', [SenderAddressController::class, 'destroy'])->name('sender-addresses.destroy');

            Route::post('assets', [AssetController::class, 'store'])->middleware('throttle:60,1')->name('assets.store');

            Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
            Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
            Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
            Route::post('templates/{template}/test', [TemplateController::class, 'test'])->middleware('throttle:20,1')->name('templates.test');
            Route::patch('templates/{template}/folder', [TemplateController::class, 'move'])->name('templates.move');
            Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
            Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
            Route::post('templates/{template}/preview', [TemplateController::class, 'preview'])->name('templates.preview');

            Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
            Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
            Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

            Route::get('variables', [VariableController::class, 'index'])->name('variables.index');
            Route::post('variables', [VariableController::class, 'store'])->name('variables.store');
            Route::put('variables/{variable}', [VariableController::class, 'update'])->name('variables.update');
            Route::delete('variables/{variable}', [VariableController::class, 'destroy'])->name('variables.destroy');

            Route::get('lists', [ContactListController::class, 'index'])->name('lists.index');
            Route::get('list-sources', [ContactListController::class, 'sources'])->name('lists.sources');
            Route::post('lists', [ContactListController::class, 'store'])->name('lists.store');
            Route::post('lists/import-preview', [ContactController::class, 'preview'])->middleware('throttle:60,1')->name('contacts.preview');
            Route::get('lists/{list}', [ContactListController::class, 'show'])->name('lists.show');
            Route::put('lists/{list}', [ContactListController::class, 'update'])->name('lists.update');
            Route::delete('lists/{list}', [ContactListController::class, 'destroy'])->name('lists.destroy');
            Route::get('lists/{list}/contacts', [ContactController::class, 'index'])->name('contacts.index');
            Route::post('lists/{list}/contacts', [ContactController::class, 'store'])->name('contacts.store');
            Route::post('lists/{list}/import', [ContactController::class, 'import'])->middleware('throttle:30,1')->name('contacts.import');
            Route::delete('lists/{list}/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
            Route::post('lists/{list}/contacts/{contact}/fix', [ContactController::class, 'fix'])->name('contacts.fix');
            Route::post('lists/{list}/fix-typos', [ContactController::class, 'fixAll'])->name('contacts.fix-all');
            Route::post('lists/{list}/check', [ContactListController::class, 'check'])->middleware('throttle:10,1')->name('lists.check');
            Route::get('lists/{list}/fields', [ContactListController::class, 'fields'])->name('lists.fields');
            Route::get('lists/{list}/tags', [ContactListController::class, 'tags'])->name('lists.tags');
            Route::post('lists/{list}/count', [ContactListController::class, 'count'])->name('lists.count');
            Route::post('lists/{list}/sync', [ContactListController::class, 'sync'])->middleware('throttle:10,1')->name('lists.sync');

            Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
            Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
            Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
            Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
            Route::post('campaigns/{campaign}/test', [CampaignController::class, 'test'])->middleware('throttle:20,1')->name('campaigns.test');
            Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
            Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
            Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
            Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

            Route::get('messages', [AdminMessageController::class, 'index'])->name('messages.index');
            Route::get('messages/{uuid}', [AdminMessageController::class, 'show'])->name('messages.show');

            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::get('audit-log', [UserController::class, 'auditLog'])->name('audit-log.index');

            Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
            Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');

            Route::get('suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');

            // личные ключи MCP: каждый управляет только своими
            Route::get('mcp-tokens', [McpTokenController::class, 'index'])->name('mcp-tokens.index');
            Route::post('mcp-tokens', [McpTokenController::class, 'store'])->middleware('throttle:10,1')->name('mcp-tokens.store');
            Route::delete('mcp-tokens/{token}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');
        });
    });
});

// Админ-интерфейс на своём поддомене (SENDER_UI_URL): весь поддомен отдаёт админку,
// кроме API выше и статики (её отдаёт nginx).
if ($uiHost = SenderUi::host()) {
    Route::domain($uiHost)->group(function (): void {
        Route::get('confirm-address/{token}', [ConfirmSenderAddressController::class, 'show'])
            ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}')->name('sender.ui-host.confirm-address');
        Route::post('confirm-address/{token}', [ConfirmSenderAddressController::class, 'store'])
            ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}');
        Route::middleware('web')->group(function (): void {
            Route::get('oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->whereIn('provider', ['yandex', 'vkid'])->name('sender.ui-host.oauth.redirect');
            Route::get('oauth/{provider}/callback', [OAuthController::class, 'callback'])->whereIn('provider', ['yandex', 'vkid'])->middleware('throttle:30,1');
        });
        Route::get('t/o/{uuid}', [TrackController::class, 'open'])->whereUuid('uuid');
        Route::get('t/c/{uuid}', [TrackController::class, 'click'])->whereUuid('uuid');
        Route::get('unsubscribe/{uuid}', [UnsubscribeController::class, 'show'])->whereUuid('uuid')->name('sender.ui-host.unsubscribe');
        Route::post('unsubscribe/{uuid}', [UnsubscribeController::class, 'store'])->middleware('throttle:30,1')->whereUuid('uuid');
        Route::get('confirm-subscription/{token}', [ConfirmSubscriptionController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{1,64}')->name('sender.ui-host.confirm-subscription');
        Route::post('confirm-subscription/{token}', [ConfirmSubscriptionController::class, 'store'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{1,64}');
        Route::post('mcp', [McpController::class, 'handle'])->middleware(AuthenticateMcpToken::class)->name('sender.ui-host.mcp');
        Route::match(['GET', 'DELETE'], 'mcp', [McpController::class, 'methodNotAllowed']);
        Route::get('{path?}', fn () => SenderUi::indexResponse())
            ->where('path', '(?!api/|sender-static/|storage/).*')->name('sender.ui-host');
    });
}

// Подтверждение адреса отправителя по ссылке из письма (страница без входа в админку)
Route::get('sender/confirm-address/{token}', [ConfirmSenderAddressController::class, 'show'])
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}')->name('sender.confirm-address');
Route::post('sender/confirm-address/{token}', [ConfirmSenderAddressController::class, 'store'])
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}');

// Вход в Sender через Яндекс ID и VK ID (нужна сессия: Socialite хранит в ней state и PKCE)
Route::middleware('web')->group(function (): void {
    Route::get('sender/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->whereIn('provider', ['yandex', 'vkid'])->name('sender.oauth.redirect');
    Route::get('sender/oauth/{provider}/callback', [OAuthController::class, 'callback'])->whereIn('provider', ['yandex', 'vkid'])->middleware('throttle:30,1')->name('sender.oauth.callback');
});

// Учёт открытий и переходов по ссылкам из писем рассылок
Route::get('sender/t/o/{uuid}', [TrackController::class, 'open'])->whereUuid('uuid')->name('sender.track.open');
Route::get('sender/t/c/{uuid}', [TrackController::class, 'click'])->whereUuid('uuid')->name('sender.track.click');

// Отписка от рассылки по ссылке из письма
Route::get('sender/unsubscribe/{uuid}', [UnsubscribeController::class, 'show'])->whereUuid('uuid')->name('sender.unsubscribe');
Route::post('sender/unsubscribe/{uuid}', [UnsubscribeController::class, 'store'])->middleware('throttle:30,1')->whereUuid('uuid');

// Подтверждение подписки по ссылке из письма (адреса, загруженные в базу без согласия)
Route::get('sender/confirm-subscription/{token}', [ConfirmSubscriptionController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{1,64}')->name('sender.confirm-subscription');
Route::post('sender/confirm-subscription/{token}', [ConfirmSubscriptionController::class, 'store'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{1,64}');

// Админка по /sender на домене приложения. Если у неё свой поддомен, старый адрес ведёт туда.
Route::get('sender/{path?}', function (?string $path = null) {
    if (SenderUi::host() !== null) {
        return redirect()->away(SenderUi::url($path ?? ''), 301);
    }

    return SenderUi::indexResponse();
})->where('path', '.*')->name('sender.ui');
