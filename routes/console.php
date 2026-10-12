<?php

use App\Models\AssistantQuestion;
use App\Models\LibraryDocumentOpen;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// журнал ИИ-помощника (вопросы и открытия документов) старше services.assistant.history_months
Schedule::command('model:prune', ['--model' => [AssistantQuestion::class, LibraryDocumentOpen::class]])->dailyAt('04:10')->withoutOverlapping();
