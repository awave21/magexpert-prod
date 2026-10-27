<?php

use App\Http\Controllers\Api\PayKeeperWebhookController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Здесь регистрируются API маршруты для приложения. Эти маршруты
| загружаются RouteServiceProvider в группе "api" middleware.
| Они не включают CSRF защиту и web middleware.
|
*/

// POST оповещения PayKeeper (без web middleware, без CSRF защиты)
Route::post('/webhook/paykeeper', [PayKeeperWebhookController::class, 'handleWebhook'])
    ->middleware('verify.paykeeper.webhook')
    ->name('webhook.paykeeper');

// События Sender (отписка, жалоба): подпись проверяется в контроллере
Route::post('/webhooks/sender', \App\Http\Controllers\Webhooks\SenderWebhookController::class)
    ->name('webhooks.sender');

// API для пользователей
Route::post('/users', [UserController::class, 'store']);

Route::middleware(\App\Mcp\Http\AuthenticateMcpToken::class)->group(function () {
    Route::post('/mcp', [\App\Mcp\Http\McpController::class, 'handle'])->name('mcp');
    Route::match(['get', 'delete'], '/mcp', [\App\Mcp\Http\McpController::class, 'methodNotAllowed']);
});
