<?php

namespace App\Services\Assistant;

use App\Models\LibraryChunk;
use App\Models\LibraryIndex;
use App\Models\MedicalLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

/**
 * Кладёт фрагменты документа библиотеки в поисковый индекс: считает векторы через ИИ-шлюз
 * (та же модель, что и для вопросов) и заменяет старые фрагменты документа одной транзакцией.
 * Векторы неизменившихся фрагментов берутся из индекса повторно, без запроса к шлюзу.
 */
class LibraryIndexer
{
    public const string SKIPPED = 'skipped';

    public const string INDEXED = 'indexed';

    private const int BATCH = 64;

    private const string PROVIDER = 'beget';

    private const int DIMENSIONS = 1536;

    /**
     * @param  array{document_id: int, sha256: string, pages: int, language: string, chunks: array<int, array{type: string, page_start: int, page_end: int, headings: array<int, string>, text: string, embed_text: string, tokens: int}>}  $export
     * @return array{status: string, chunks: int, embedded_tokens: int}
     */
    public function index(MedicalLibrary $document, array $export, string $parser, ?string $parserVersion, bool $force = false): array
    {
        $model = self::embeddingModel();
        $current = $document->libraryIndex;

        // большой документ (сотни фрагментов по 1536 чисел) не укладывается в стандартные 128 МБ PHP
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '512M');
        }

        if (! $force && $current
            && $current->file_sha256 === $export['sha256']
            && $current->parser === $parser
            && $current->parser_version === $parserVersion
            && $current->embedding_model === $model) {
            return ['status' => self::SKIPPED, 'chunks' => $current->chunks_count, 'embedded_tokens' => 0];
        }

        $chunks = collect($export['chunks'])->values()->map(fn (array $chunk, int $position): array => [
            'position' => $position,
            'type' => $chunk['type'],
            'page_start' => $chunk['page_start'],
            'page_end' => $chunk['page_end'],
            'headings' => $chunk['headings'],
            'content' => $chunk['text'],
            'embed_text' => $chunk['embed_text'],
            'content_sha256' => hash('sha256', $chunk['embed_text']),
            'tokens' => $chunk['tokens'],
        ]);

        $known = $current?->embedding_model === $model
            ? $document->libraryChunks()->toBase()->whereIn('content_sha256', $chunks->pluck('content_sha256'))->pluck('embedding', 'content_sha256')->all()
            : [];

        // Векторы запрашиваются и пишутся в базу пачками: весь документ целиком в память не помещается (сотни фрагментов по 1536 чисел)
        $embeddedTokens = 0;
        DB::transaction(function () use ($document, $chunks, &$known, &$embeddedTokens, $export, $parser, $parserVersion, $model): void {
            $document->libraryChunks()->delete();

            foreach ($chunks->chunk(self::BATCH) as $batch) {
                $missing = $batch->reject(fn (array $chunk): bool => isset($known[$chunk['content_sha256']]))->unique('content_sha256');
                if ($missing->isNotEmpty()) {
                    // шлюз изредка не отвечает на большую пачку: три попытки, прежде чем откатить документ
                    $vectors = retry(3, fn (): array => Embeddings::for($missing->pluck('embed_text')->all())
                        ->dimensions(self::DIMENSIONS)
                        ->timeout(120)
                        ->generate(self::PROVIDER, $model)
                        ->embeddings, 5000);
                    foreach ($missing->pluck('content_sha256')->values() as $index => $hash) {
                        $known[$hash] = json_encode($vectors[$index], JSON_THROW_ON_ERROR);
                    }
                    $embeddedTokens += $missing->sum('tokens');
                }

                $now = now();
                LibraryChunk::query()->insert($batch->map(fn (array $chunk): array => [
                    ...$chunk,
                    'medical_library_id' => $document->id,
                    'headings' => json_encode($chunk['headings'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'embedding' => $known[$chunk['content_sha256']],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            LibraryIndex::updateOrCreate(['medical_library_id' => $document->id], [
                'file_sha256' => $export['sha256'],
                'parser' => $parser,
                'parser_version' => $parserVersion,
                'embedding_model' => $model,
                'language' => $export['language'],
                'pages' => $export['pages'],
                'chunks_count' => $chunks->count(),
                'indexed_at' => now(),
            ]);
        });

        return [
            'status' => self::INDEXED,
            'chunks' => $chunks->count(),
            'embedded_tokens' => $embeddedTokens,
        ];
    }

    /**
     * Хеш файла на диске сайта: фрагменты из выгрузки принимаются, только если PDF с тех пор не меняли.
     */
    public static function fileHash(MedicalLibrary $document): ?string
    {
        $disk = Storage::disk('public');

        return $document->file_path && $disk->exists($document->file_path)
            ? hash_file('sha256', $disk->path($document->file_path))
            : null;
    }

    public static function embeddingModel(): string
    {
        return (string) config('ai.providers.'.self::PROVIDER.'.models.embeddings.default');
    }
}
