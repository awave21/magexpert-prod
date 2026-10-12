<?php

use App\Models\LibraryChunk;
use App\Models\LibraryIndex;
use App\Models\MedicalLibrary;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

/**
 * Выгрузка export_chunks.py для одного документа.
 *
 * @param  array<int, string>  $texts
 */
function writeExport(MedicalLibrary $document, string $sha256, array $texts): string
{
    $path = tempnam(sys_get_temp_dir(), 'chunks');
    file_put_contents($path, json_encode([
        'generated_at' => '2026-10-12T19:00:00+00:00',
        'parser' => 'docling',
        'parser_version' => '2.138.0',
        'documents' => [[
            'document_id' => $document->id,
            'sha256' => $sha256,
            'pages' => 5,
            'language' => 'en',
            'chunks' => array_map(fn (string $text, int $index): array => [
                'type' => $index === 1 ? 'table' : 'text',
                'page_start' => $index + 1,
                'page_end' => $index + 1,
                'headings' => ['Results'],
                'text' => $text,
                'embed_text' => "Results\n{$text}",
                'tokens' => 10,
            ], $texts, array_keys($texts)),
        ]],
    ]));

    return $path;
}

beforeEach(function () {
    Storage::fake('public');
    Storage::disk('public')->put('medical-library/7/files/paper.pdf', 'pdf-content');
    $this->sha = hash('sha256', 'pdf-content');
    $this->document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/7/files/paper.pdf']);
    Embeddings::fake();
});

test('импорт кладёт фрагменты с векторами и состоянием индекса', function () {
    $this->artisan('library:import', ['file' => writeExport($this->document, $this->sha, ['Intro text', '| a | b |'])])->assertSuccessful();

    expect(LibraryChunk::query()->where('medical_library_id', $this->document->id)->orderBy('position')->get())
        ->toHaveCount(2)
        ->sequence(
            fn ($chunk) => $chunk->type->toBe('text')->page_start->toBe(1)->embedding->toHaveCount(1536),
            fn ($chunk) => $chunk->type->toBe('table')->page_start->toBe(2),
        );
    expect(LibraryIndex::firstWhere('medical_library_id', $this->document->id))
        ->parser->toBe('docling')->file_sha256->toBe($this->sha)->chunks_count->toBe(2)->language->toBe('en');
});

test('повторный импорт без изменений не обращается к шлюзу, а изменённые фрагменты пересчитываются частично', function () {
    $sent = [];
    Embeddings::fake(function ($prompt) use (&$sent) {
        $sent[] = $prompt->inputs;

        return array_map(fn () => Embeddings::fakeEmbedding(1536), $prompt->inputs);
    });

    $this->artisan('library:import', ['file' => writeExport($this->document, $this->sha, ['One', 'Two'])])->assertSuccessful();
    $this->artisan('library:import', ['file' => writeExport($this->document, $this->sha, ['One', 'Two'])])->assertSuccessful();
    $this->artisan('library:import', ['file' => writeExport($this->document, $this->sha, ['One', 'Three']), '--force' => true])->assertSuccessful();

    expect($sent)->toBe([["Results\nOne", "Results\nTwo"], ["Results\nThree"]]);
    expect(LibraryChunk::query()->where('medical_library_id', $this->document->id)->orderBy('position')->pluck('content')->all())->toBe(['One', 'Three']);
});

test('выгрузка от другой версии PDF не импортируется', function () {
    $this->artisan('library:import', ['file' => writeExport($this->document, str_repeat('b', 64), ['Intro'])])->assertFailed();

    expect(LibraryChunk::query()->count())->toBe(0);
    Embeddings::assertNothingGenerated();
});

test('пробный запуск только считает токены', function () {
    $this->artisan('library:import', ['file' => writeExport($this->document, $this->sha, ['Intro', 'More']), '--dry-run' => true])
        ->expectsOutputToContain('Будет отправлено на векторы: 20 токенов')
        ->assertSuccessful();

    expect(LibraryChunk::query()->count())->toBe(0);
    Embeddings::assertNothingGenerated();
});

test('новый PDF из админки сам попадает в поиск через разбор poppler, а уже проиндексированный файл не трогается', function () {
    config(['services.assistant.driver' => 'rag']);
    Storage::disk('public')->put('medical-library/8/files/new.pdf', 'new-pdf');
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'pdfinfo' => Process::result("Title: x\nPages:          2\n"),
        $process->command === ['pdftotext', '-v'] => Process::result(errorOutput: 'pdftotext version 24.02.0'),
        $process->command[2] === '1' => Process::result("Максимальная доза лидокаина\nсоставляет 4,5 мг/кг.\n\nВторой абзац."),
        default => Process::result('Page two text'),
    });

    $document = MedicalLibrary::factory()->create(['file_path' => 'medical-library/8/files/new.pdf']);

    expect(LibraryIndex::firstWhere('medical_library_id', $document->id))
        ->parser->toBe('poppler')->parser_version->toBe('24.02.0')->pages->toBe(2)->language->toBe('ru');
    expect(LibraryChunk::query()->where('medical_library_id', $document->id)->orderBy('position')->get(['page_start', 'content'])->toArray())->toBe([
        ['page_start' => 1, 'content' => "Максимальная доза лидокаина составляет 4,5 мг/кг.\n\nВторой абзац."],
        ['page_start' => 2, 'content' => 'Page two text'],
    ]);

    $document->update(['title' => 'Новое название']);
    Process::assertRanTimes(fn ($process) => $process->command[0] === 'pdfinfo', 1);
});

test('без режима rag новые документы не индексируются', function () {
    config(['services.assistant.driver' => null]);
    Process::fake();

    MedicalLibrary::factory()->create(['file_path' => 'medical-library/7/files/paper.pdf']);

    Process::assertNothingRan();
    expect(LibraryChunk::query()->count())->toBe(0);
});
