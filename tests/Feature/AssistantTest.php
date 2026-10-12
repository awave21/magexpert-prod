<?php

use App\Models\Event;
use App\Models\MedicalLibrary;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantAssistantAccess(User $user, string $accessType = 'paid'): void
{
    Event::factory()->create()->users()->attach($user->id, [
        'access_type' => $accessType,
        'payment_status' => $accessType === 'free' ? 'free' : 'completed',
        'access_granted_at' => now(),
        'is_active' => true,
    ]);
}

test('гость не попадает на страницу помощника', function () {
    $this->get('/cabinet/assistant')->assertRedirect('/login');
});

test('без оплаченного или выданного доступа помощник закрыт', function (?string $accessType) {
    $user = User::factory()->create();
    if ($accessType) {
        grantAssistantAccess($user, $accessType);
    }

    $this->actingAs($user)->get('/cabinet/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Cabinet/Assistant')
        ->where('hasAccess', false));

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])->assertForbidden();
})->with([
    'нет мероприятий' => [null],
    'только бесплатная регистрация' => ['free'],
]);

test('с оплаченным или выданным доступом помощник открыт', function (string $accessType) {
    $user = User::factory()->create();
    grantAssistantAccess($user, $accessType);

    $this->actingAs($user)->get('/cabinet/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('hasAccess', true)
        ->where('available', false));
})->with(['paid', 'promotional', 'admin', 'invited']);

test('пока поиск не подключён, вопрос получает 503', function () {
    $user = User::factory()->create();
    grantAssistantAccess($user);

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])->assertStatus(503);
});

test('демо-режим отвечает со сносками на документы библиотеки', function () {
    config(['services.assistant.driver' => 'demo']);
    $user = User::factory()->create();
    grantAssistantAccess($user);
    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/guide.pdf']);

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('sources.0.number', 1)
        ->assertJsonPath('sources.0.document_id', $document->id)
        ->assertJsonPath('sources.0.url', $document->file_url.'#page='.($document->id % 12 + 1));
});

test('демо-режим не включается в продакшене', function () {
    config(['services.assistant.driver' => 'demo']);
    app()->detectEnvironment(fn () => 'production');
    $user = User::factory()->create();
    grantAssistantAccess($user);

    $this->actingAs($user)->get('/cabinet/assistant')->assertInertia(fn (Assert $page) => $page->where('available', false));
});

test('пустой и слишком длинный вопрос отклоняются', function (string $question) {
    config(['services.assistant.driver' => 'demo']);
    $user = User::factory()->create();
    grantAssistantAccess($user);

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => $question])->assertJsonValidationErrors('question');
})->with([
    'пусто' => [''],
    'длиннее 1000 символов' => [str_repeat('а', 1001)],
]);
