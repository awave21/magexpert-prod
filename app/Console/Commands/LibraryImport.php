<?php

namespace App\Console\Commands;

use App\Models\MedicalLibrary;
use App\Services\Assistant\LibraryIndexer;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Загружает в поисковый индекс ИИ-помощника фрагменты, подготовленные на Mac скриптом
 * tools/docling/export_chunks.py (Docling: структура, таблицы, страницы). Векторы считаются здесь,
 * через ИИ-шлюз, той же моделью, что и вопросы. Повторный запуск пропускает неизменившиеся документы.
 */
class LibraryImport extends Command
{
    protected $signature = 'library:import
                            {file : Путь к library-chunks.json}
                            {--document=* : Только эти ID документов}
                            {--force : Пересчитать даже неизменившиеся документы}
                            {--dry-run : Только проверить файл и посчитать токены, без запросов к шлюзу}';

    protected $description = 'Загрузить фрагменты документов библиотеки (выгрузка Docling) в поиск ИИ-помощника';

    public function handle(LibraryIndexer $indexer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        try {
            $export = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Файл повреждён: '.$exception->getMessage());

            return self::FAILURE;
        }

        $parser = (string) ($export['parser'] ?? '');
        if ($parser === '' || ! is_array($export['documents'] ?? null)) {
            $this->error('Это не выгрузка export_chunks.py: нет полей parser и documents.');

            return self::FAILURE;
        }

        $only = collect($this->option('document'))->map(fn ($id): int => (int) $id);
        $documents = collect($export['documents'])->when($only->isNotEmpty(), fn ($items) => $items->whereIn('document_id', $only));
        $models = MedicalLibrary::query()->with('libraryIndex')->findMany($documents->pluck('document_id'))->keyBy('id');

        $this->info(sprintf('Выгрузка %s %s от %s: документов %d. Модель векторов: %s',
            $parser, $export['parser_version'] ?? '', $export['generated_at'] ?? '?', $documents->count(), LibraryIndexer::embeddingModel()));

        $rows = [];
        $tokens = 0;
        $failed = 0;
        foreach ($documents as $item) {
            $id = (int) $item['document_id'];
            $document = $models->get($id);

            if (! $document) {
                $rows[] = [$id, '—', 'нет в библиотеке на сайте', 0];

                continue;
            }

            $hash = LibraryIndexer::fileHash($document);
            if ($hash !== $item['sha256']) {
                $rows[] = [$id, $document->title, $hash ? 'PDF на сайте другой, разберите заново' : 'PDF на сайте не найден', 0];
                $failed++;

                continue;
            }

            if ($this->option('dry-run')) {
                $chunkTokens = collect($item['chunks'])->sum('tokens');
                $tokens += $chunkTokens;
                $rows[] = [$id, $document->title, count($item['chunks']).' фрагм.', $chunkTokens];

                continue;
            }

            try {
                $result = $indexer->index($document, $item, $parser, $export['parser_version'] ?? null, (bool) $this->option('force'));
            } catch (Throwable $exception) {
                $rows[] = [$id, $document->title, 'ошибка: '.$exception->getMessage(), 0];
                $failed++;

                continue;
            }

            $tokens += $result['embedded_tokens'];
            $rows[] = [$id, $document->title, $result['status'] === LibraryIndexer::SKIPPED ? 'без изменений' : $result['chunks'].' фрагм.', $result['embedded_tokens']];
        }

        $this->table(['ID', 'Документ', 'Результат', 'Токенов'], array_map(
            fn (array $row): array => [$row[0], mb_strimwidth((string) $row[1], 0, 60, '…'), $row[2], $row[3]],
            $rows,
        ));
        $this->info(($this->option('dry-run') ? 'Будет отправлено на векторы' : 'Отправлено на векторы').": {$tokens} токенов.");

        if ($failed > 0) {
            $this->warn("С проблемами: {$failed}.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
