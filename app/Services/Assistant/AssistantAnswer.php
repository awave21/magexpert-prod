<?php

namespace App\Services\Assistant;

/**
 * Ответ помощника. В тексте ответа сноски записаны как [1], [2]: номер совпадает с полем number у источника.
 */
final class AssistantAnswer
{
    /**
     * @param  array<int, array{number: int, document_id: int, title: string, page: int, language: string, excerpt: string, url: string}>  $sources
     */
    public function __construct(
        public readonly string $text,
        public readonly array $sources = [],
    ) {}

    public static function notFound(): self
    {
        return new self('В документах библиотеки ответа на этот вопрос нет. Попробуйте переформулировать вопрос или спросить о другом.');
    }

    public function found(): bool
    {
        return $this->sources !== [];
    }

    /**
     * @return array{answer: string, found: bool, sources: array<int, array<string, int|string>>}
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->text,
            'found' => $this->found(),
            'sources' => $this->sources,
        ];
    }
}
