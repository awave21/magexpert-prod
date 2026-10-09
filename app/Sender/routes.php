<?php

use App\Sender\Http\Controllers\Admin\ApiKeyController;
use App\Sender\Http\Controllers\Admin\AssetController;
use App\Sender\Http\Controllers\Admin\AuthController;
use App\Sender\Http\Controllers\Admin\DomainController;
use App\Sender\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Sender\Http\Controllers\Admin\SenderAddressController;
use App\Sender\Http\Controllers\Admin\StatsController;
use App\Sender\Http\Controllers\Admin\SuppressionController;
use App\Sender\Http\Controllers\Admin\TemplateController;
use App\Sender\Http\Controllers\Admin\TemplateFolderController;
use App\Sender\Http\Controllers\Admin\VariableController;
use App\Sender\Http\Controllers\ConfirmSenderAddressController;
use App\Sender\Http\Controllers\MessageController;
use App\Sender\Http\Middleware\AuthenticateApiKey;
use App\Sender\Http\Middleware\AuthenticateUser;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Facades\Route;

Route::prefix('api/sender/v1')->middleware('api')->group(function (): void {
    Route::middleware(AuthenticateApiKey::class)->group(function (): void {
        Route::post('messages', [MessageController::class, 'store']);
        Route::get('messages/{uuid}', [MessageController::class, 'show']);
    });

    Route::prefix('admin')->name('sender.admin.')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,60')->name('register');

        Route::middleware(AuthenticateUser::class)->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::get('stats', StatsController::class)->name('stats');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
            Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
            Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
            Route::post('domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
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

            Route::get('messages', [AdminMessageController::class, 'index'])->name('messages.index');
            Route::get('messages/{uuid}', [AdminMessageController::class, 'show'])->name('messages.show');

            Route::get('suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');
        });
    });
});

// Админ-интерфейс на своём поддомене (SENDER_UI_URL): весь поддомен отдаёт админку,
// кроме API выше и статики (её отдаёт nginx).
if ($uiHost = SenderUi::host()) {
    Route::domain($uiHost)->group(function (): void {
        Route::get('confirm-address/{token}', ConfirmSenderAddressController::class)
            ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}')->name('sender.ui-host.confirm-address');
        Route::get('{path?}', fn () => SenderUi::indexResponse())
            ->where('path', '(?!api/|sender-static/|storage/).*')->name('sender.ui-host');
    });
}

// Подтверждение адреса отправителя по ссылке из письма (страница без входа в админку)
Route::get('sender/confirm-address/{token}', ConfirmSenderAddressController::class)
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}')->name('sender.confirm-address');

// Админка по /sender на домене приложения. Если у неё свой поддомен, старый адрес ведёт туда.
Route::get('sender/{path?}', function (?string $path = null) {
    if (SenderUi::host() !== null) {
        return redirect()->away(SenderUi::url($path ?? ''), 301);
    }

    return SenderUi::indexResponse();
})->where('path', '.*')->name('sender.ui');
