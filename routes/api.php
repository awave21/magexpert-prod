<?php

use App\Http\Controllers\Api\PayKeeperWebhookController;
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