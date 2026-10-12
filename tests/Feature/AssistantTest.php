<?php

use App\Models\MedicalLibrary;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('гость не попадает на страницу помощника', function () {
    $this->get('/cabinet/assistant')->assertRedirect('/login');
});

test('помощник открыт любому вошедшему пользователю, даже без доступов к мероприятиям', function () {
    $this->actingAs(User::factory()->create())->get('/cabinet/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Cabinet/Assistant')
        ->where('available', false)
        ->missing('hasAccess'));
});

test('пока поиск не подключён, вопрос получает 503', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])->assertStatus(503);
});

test('демо-режим отвечает со сносками на документы библиотеки', function () {
    config(['services.assistant.driver' => 'demo']);
    $user = User::factory()->create();
    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/guide.pdf']);

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('sources.0.number', 1)
        ->assertJsonPath('sources.0.document_id', $document->id)
        ->assertJsonPath('sources.0.url', route('documents.show', ['document' => $document->id, 'page' => $document->id % 12 + 1, 'question' => App\Models\AssistantQuestion::sole()->id]));
});

test('демо-режим не включается в продакшене', function () {
    config(['services.assistant.driver' => 'demo']);
    app()->detectEnvironment(fn () => 'production');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cabinet/assistant')->assertInertia(fn (Assert $page) => $page->where('available', false));
});

test('пустой и слишком длинный вопрос отклоняются', function (string $question) {
    config(['services.assistant.driver' => 'demo']);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => $question])->assertJsonValidationErrors('question');
})->with([
    'пусто' => [''],
    'длиннее 1000 символов' => [str_repeat('а', 1001)],
]);
