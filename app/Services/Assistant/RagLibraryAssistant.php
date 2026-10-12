<?php

namespace App\Services\Assistant;

use App\Models\LibraryChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * ИИ-помощник по библиотеке: гибридный поиск фрагментов (векторы pgvector + полнотекстовый поиск RU/EN,
 * слияние Reciprocal Rank Fusion), затем ответ модели только по найденному. Ссылки на страницы PDF
 * собирает код по сноскам модели, поэтому выдуманных ссылок быть не может.
 */
class RagLibraryAssistant implements LibraryAssistant
{
    private const int CANDIDATES = 30;

    private const int CONTEXT_CHUNKS = 6;

    private const int RRF_K = 60;

    public function isAvailable(): bool
    {
        return filled(config('ai.providers.beget.key')) && LibraryChunk::query()->exists();
    }

    public function answer(string $question): AssistantAnswer
    {
        $chunks = $this->search($question);
        if ($chunks->isEmpty()) {
            return AssistantAnswer::notFound();
        }

        $response = (new LibraryAnswerAgent)->prompt($this->prompt($question, $chunks));
        $text = trim($response->text);
        $answer = $text === '' || str_contains($text, LibraryAnswerAgent::NOT_FOUND)
            ? AssistantAnswer::notFound()
            : ($this->withSources($text, $chunks) ?? AssistantAnswer::notFound());

        return $answer->withUsage(config('ai.providers.beget.models.text.default'), $response->usage->inputTokens, $response->usage->outputTokens);
    }

    /**
     * Лучшие фрагменты по смыслу и по словам, объединённые RRF. Пусто, если ничего не похоже на вопрос.
     *
     * @return Collection<int, LibraryChunk>
     */
    public function search(string $question): Collection
    {
        $vector = Embeddings::for([$question])
            ->dimensions(1536)
            ->generate('beget', LibraryIndexer::embeddingModel())
            ->first();

        $byMeaning = LibraryChunk::query()
            ->select('id')
            ->selectVectorDistance('embedding', $vector, 'distance')
            ->orderByVectorDistance('embedding', $vector)
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (LibraryChunk $chunk): bool => 1 - (float) $chunk->distance >= (float) config('services.assistant.min_similarity'))
            ->pluck('id');

        $words = $this->searchWords($question);
        $byWords = $words === ''
            ? collect()
            : LibraryChunk::query()
                ->whereRaw("search @@ (websearch_to_tsquery('russian', ?) || websearch_to_tsquery('english', ?))", [$words, $words])
                ->orderByRaw("ts_rank_cd(search, websearch_to_tsquery('russian', ?) || websearch_to_tsquery('english', ?)) desc", [$words, $words])
                ->limit(self::CANDIDATES)
                ->pluck('id');

        $scores = [];
        foreach ([$byMeaning, $byWords] as $ranking) {
            foreach ($ranking->values() as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            }
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, self::CONTEXT_CHUNKS);

        $chunks = LibraryChunk::query()->with('document.libraryIndex')->findMany($ids)->keyBy('id');

        return collect($ids)->map(fn (int $id): ?LibraryChunk => $chunks->get($id))->filter()->values();
    }

    /**
     * Слова вопроса через «or» для websearch_to_tsquery: длинный вопрос целиком почти никогда не совпадает дословно.
     */
    private function searchWords(string $question): string
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-]{2,}/u', Str::lower($question), $matches);

        return implode(' or ', array_slice(array_unique($matches[0]), 0, 12));
    }

    /**
     * @param  Collection<int, LibraryChunk>  $chunks
     */
    private function prompt(string $question, Collection $chunks): string
    {
        $fragments = $chunks->values()->map(function (LibraryChunk $chunk, int $index): string {
            $pages = $chunk->page_start === $chunk->page_end ? "стр. {$chunk->page_start}" : "стр. {$chunk->page_start}–{$chunk->page_end}";
            $section = $chunk->headings ? ', раздел: '.implode(' › ', $chunk->headings) : '';

            return '['.($index + 1)."] Документ «{$chunk->document->title}», {$pages}{$section}\n{$chunk->content}";
        })->implode("\n\n");

        return "Вопрос: {$question}\n\nФрагменты из библиотеки:\n\n{$fragments}";
    }

    /**
     * Оставляет в ответе только сноски на реальные фрагменты, нумерует источники по порядку появления
     * и склеивает сноски на одну и ту же страницу одного документа. Без единой сноски ответ не принимается.
     *
     * @param  Collection<int, LibraryChunk>  $chunks
     */
    private function withSources(string $text, Collection $chunks): ?AssistantAnswer
    {
        $sources = [];
        $numbers = [];

        $text = preg_replace_callback('/\[(\d+)\]/', function (array $match) use ($chunks, &$sources, &$numbers): string {
            $chunk = $chunks->get((int) $match[1] - 1);
            if (! $chunk) {
                return '';
            }

            $key = $chunk->medical_library_id.':'.$chunk->page_start;
            if (! isset($numbers[$key])) {
                $numbers[$key] = count($numbers) + 1;
                $sources[] = $this->source($chunk, $numbers[$key]);
            }

            return '['.$numbers[$key].']';
        }, $text);

        $text = trim((string) preg_replace(['/(\[\d+\])(?:\1)+/', '/[ \t]+([.,;:])/'], ['$1', '$1'], (string) $text));

        return $sources === [] ? null : new AssistantAnswer($text, $sources);
    }

    /**
     * @return array{number: int, document_id: int, title: string, page: int, language: string, excerpt: string, url: string}
     */
    private function source(LibraryChunk $chunk, int $number): array
    {
        $document = $chunk->document;

        return [
            'number' => $number,
            'document_id' => $document->id,
            'title' => $document->title,
            'page' => $chunk->page_start,
            'language' => $document->libraryIndex?->language ?? $document->language,
            'excerpt' => Str::limit((string) preg_replace('/[|\s]+/u', ' ', $chunk->content), 200),
            'url' => $document->file_url.'#page='.$chunk->page_start,
        ];
    }
}
