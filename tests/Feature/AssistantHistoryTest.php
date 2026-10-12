<?php

use App\Models\AssistantQuestion;
use App\Models\LibraryDocumentOpen;
use App\Models\MedicalLibrary;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function assistantStaff(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

    return $user;
}

test('вопрос попадает в журнал, а ссылки на источники ведут через сайт', function () {
    config(['services.assistant.driver' => 'demo']);
    $user = User::factory()->create();
    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/9/files/hpv.pdf']);
    $page = $document->id % 12 + 1;

    $response = $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => '  Когда вакцинировать от ВПЧ?  '])->assertOk();

    $record = AssistantQuestion::sole();
    expect($record)
        ->user_id->toBe($user->id)
        ->question->toBe('Когда вакцинировать от ВПЧ?')
        ->found->toBeTrue()
        ->sources->toEqual([['document_id' => $document->id, 'title' => $document->title, 'page' => $page]]);
    $response->assertJsonPath('sources.0.url', route('documents.show', ['document' => $document->id, 'page' => $page, 'question' => $record->id]));
});

test('открытие документа из библиотеки записывается и ведёт на PDF, гостю тоже', function () {
    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/9/files/hpv.pdf']);

    $this->get(route('documents.show', ['document' => $document->id, 'page' => 4]))
        ->assertRedirect(Storage::url('medical-library/9/files/hpv.pdf').'#page=4');

    expect(LibraryDocumentOpen::sole())
        ->medical_library_id->toBe($document->id)
        ->user_id->toBeNull()
        ->source->toBe(LibraryDocumentOpen::FROM_LIBRARY)
        ->page->toBe(4);
});

test('клик по источнику засчитывается вопросу, только если вопрос свой', function () {
    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/9/files/hpv.pdf']);
    $owner = User::factory()->create();
    $question = AssistantQuestion::factory()->for($owner)->create();

    $this->actingAs(User::factory()->create())->get(route('documents.show', ['document' => $document->id, 'question' => $question->id]));
    $this->actingAs($owner)->get(route('documents.show', ['document' => $document->id, 'page' => 99999, 'question' => $question->id]));

    expect(LibraryDocumentOpen::query()->orderBy('id')->get(['source', 'assistant_question_id', 'page'])->toArray())->toBe([
        ['source' => 'library', 'assistant_question_id' => null, 'page' => null],
        ['source' => 'assistant', 'assistant_question_id' => $question->id, 'page' => null],
    ]);
});

test('админ видит сводку, вопросы без ответа и популярные документы', function () {
    $document = MedicalLibrary::factory()->create(['title' => 'Рекомендации по ВПЧ']);
    $question = AssistantQuestion::factory()->create([
        'question' => 'Когда вакцинировать?',
        'sources' => [['document_id' => $document->id, 'title' => 'Рекомендации по ВПЧ', 'page' => 3]],
        'input_tokens' => 1_000_000,
        'output_tokens' => 100_000,
        'created_at' => now()->subHour(),
    ]);
    AssistantQuestion::factory()->unanswered()->count(2)->create(['question' => 'Как настроить роутер?']);
    AssistantQuestion::factory()->unanswered()->create(['question' => 'Старый вопрос', 'created_at' => now()->subDays(40)]);
    LibraryDocumentOpen::create(['medical_library_id' => $document->id, 'assistant_question_id' => $question->id, 'source' => 'assistant', 'page' => 3]);
    LibraryDocumentOpen::create(['medical_library_id' => $document->id, 'source' => 'library']);

    $this->actingAs(assistantStaff())->get('/admin/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Assistant')
        ->where('summary.questions', 3)
        ->where('summary.found', 1)
        ->where('summary.opens_assistant', 1)
        ->where('summary.opens_library', 1)
        ->where('summary.cost', 18)
        ->has('missing', 1)
        ->where('missing.0.question', 'Как настроить роутер?')
        ->where('missing.0.times', 2)
        ->where('documents.0.title', 'Рекомендации по ВПЧ')
        ->where('documents.0.cited', 1)
        ->where('documents.0.opens_assistant', 1)
        ->where('documents.0.opens_library', 1)
        ->where('questions.total', 3)
        ->where('questions.data.2.sources.0.opened', true));

    $this->actingAs(assistantStaff())->get('/admin/assistant?status=missing&search=роутер&period=7')
        ->assertInertia(fn (Assert $page) => $page->where('questions.total', 2)->where('filters.period', 7));
});

test('раздел видят только админы и менеджеры', function (?string $role, int $status) {
    $user = $role ? assistantStaff($role) : User::factory()->create();

    $this->actingAs($user)->get('/admin/assistant')->assertStatus($status);
})->with([
    'менеджер' => ['manager', 200],
    'редактор' => ['editor', 403],
    'врач' => [null, 403],
]);

test('записи старше срока хранения удаляются', function () {
    config(['services.assistant.history_months' => 12]);
    $old = AssistantQuestion::factory()->create(['created_at' => now()->subMonths(13)]);
    $fresh = AssistantQuestion::factory()->create();
    $document = MedicalLibrary::factory()->create();
    LibraryDocumentOpen::forceCreate(['medical_library_id' => $document->id, 'source' => 'library', 'created_at' => now()->subMonths(13)]);

    $this->artisan('model:prune', ['--model' => [AssistantQuestion::class, LibraryDocumentOpen::class]])->assertSuccessful();

    expect(AssistantQuestion::pluck('id')->all())->toBe([$fresh->id]);
    expect(LibraryDocumentOpen::count())->toBe(0);
});
