<?php

use App\Models\AssistantQuestion;
use App\Models\LibraryChunk;
use App\Models\LibraryIndex;
use App\Models\MedicalLibrary;
use App\Models\User;
use App\Services\Assistant\LibraryAnswerAgent;
use Laravel\Ai\Embeddings;

/**
 * Единичный вектор: у фрагментов с одной осью близость 1, с разными — 0.
 *
 * @return array<int, float>
 */
function axis(int $index): array
{
    $vector = array_fill(0, 1536, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

function ragUser(): User
{
    return User::factory()->create();
}

function questionPointsTo(int $index): void
{
    Embeddings::fake(fn ($prompt) => array_map(fn () => axis($index), $prompt->inputs));
}

beforeEach(function () {
    config(['services.assistant.driver' => 'rag', 'ai.providers.beget.key' => 'test-key']);

    $this->document = MedicalLibrary::factory()->create(['title' => 'Руководство по местной анестезии', 'file_path' => 'medical-library/22/files/guide.pdf', 'language' => 'ru']);
    LibraryIndex::create([
        'medical_library_id' => $this->document->id, 'file_sha256' => str_repeat('a', 64), 'parser' => 'docling',
        'embedding_model' => 'text-embedding-3-large', 'language' => 'en', 'pages' => 19, 'chunks_count' => 2, 'indexed_at' => now(),
    ]);
    LibraryChunk::factory()->for($this->document, 'document')->create([
        'position' => 0, 'page_start' => 3, 'page_end' => 3, 'embedding' => axis(0),
        'content' => 'Maximum dose of lidocaine is 4.5 mg/kg', 'embed_text' => 'Dosing. Maximum dose of lidocaine is 4.5 mg/kg',
    ]);
    LibraryChunk::factory()->for($this->document, 'document')->create([
        'position' => 1, 'page_start' => 7, 'page_end' => 7, 'embedding' => axis(1),
        'content' => 'Unrelated history section', 'embed_text' => 'History. Unrelated history section',
    ]);
});

test('отвечает по найденному фрагменту и ставит ссылку на страницу', function () {
    questionPointsTo(0);
    LibraryAnswerAgent::fake(['Максимальная доза лидокаина 4,5 мг/кг [1][1]. Ещё утверждение [9].']);

    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'Какая максимальная доза лидокаина?'])
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('answer', 'Максимальная доза лидокаина 4,5 мг/кг [1]. Ещё утверждение.')
        ->assertJsonCount(1, 'sources')
        ->assertJsonPath('sources.0.page', 3)
        ->assertJsonPath('sources.0.language', 'en')
        ->assertJsonPath('sources.0.url', route('documents.show', ['document' => $this->document->id, 'page' => 3, 'question' => AssistantQuestion::sole()->id]));

    LibraryAnswerAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '[1] Документ «Руководство по местной анестезии», стр. 3')
        && ! str_contains($prompt->prompt, 'Unrelated history'));
});

test('находит фрагмент по словам, даже если по смыслу он далёк', function () {
    questionPointsTo(5);
    LibraryAnswerAgent::fake(['Есть раздел об истории [1].']);

    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'history section'])
        ->assertJsonPath('found', true)
        ->assertJsonPath('sources.0.page', 7);
});

test('без похожих фрагментов модель не вызывается', function () {
    questionPointsTo(5);
    LibraryAnswerAgent::fake();

    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'Как настроить роутер?'])
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonCount(0, 'sources');

    LibraryAnswerAgent::assertNeverPrompted();
});

test('ответ без сносок или «нет ответа» не показывается как найденный', function (string $reply) {
    questionPointsTo(0);
    LibraryAnswerAgent::fake([$reply]);

    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза лидокаина?'])
        ->assertJsonPath('found', false);
})->with([
    'нет ответа' => [LibraryAnswerAgent::NOT_FOUND],
    'без сносок' => ['Доза зависит от веса пациента.'],
]);

test('помощник недоступен без ключа шлюза или без проиндексированных документов', function () {
    config(['ai.providers.beget.key' => null]);
    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])->assertStatus(503);

    config(['ai.providers.beget.key' => 'test-key']);
    LibraryChunk::query()->delete();
    $this->actingAs(ragUser())->postJson('/cabinet/assistant/ask', ['question' => 'Какая доза?'])->assertStatus(503);
});
