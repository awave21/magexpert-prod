<?php

use App\Models\AssistantQuestion;
use App\Models\LibraryDocumentOpen;
use App\Sender\Client\HttpContactSyncClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// журнал ИИ-помощника (вопросы и открытия документов) старше services.assistant.history_months
Schedule::command('model:prune', ['--model' => [AssistantQuestion::class, LibraryDocumentOpen::class]])->dailyAt('04:10')->withoutOverlapping();

// раз в сутки база «Пользователи MagExpert» в Sender сверяется с сайтом целиком: правки профиля уходят сразу,
// это страховка на случай пропущенной задачи или недоступного Sender. Только если синхронизация включена.
Schedule::command('sender:sync-users')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (bool) config('sender.client.users_sync') && HttpContactSyncClient::configured());
