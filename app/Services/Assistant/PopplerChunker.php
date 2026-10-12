<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Упрощённый разбор PDF на сервере через poppler (pdfinfo + pdftotext по страницам) для документов,
 * загруженных в админке. Таблиц и заголовков не восстанавливает: качественный разбор делает Docling на Mac
 * (tools/docling/export_chunks.py), а этот нужен, чтобы новый документ сразу попадал в поиск.
 */
class PopplerChunker
{
    /** Примерно 600–700 токенов: ~4 символа на токен в английском, ~2,5 в русском. */
    private const int MAX_CHARS = 2400;

    /**
     * Фрагменты в формате выгрузки export_chunks.py.
     *
     * @return array{sha256: string, pages: int, language: string, chunks: array<int, array{type: string, page_start: int, page_end: int, headings: array<int, string>, text: string, embed_text: string, tokens: int}>}
     */
    public function chunk(string $path): array
    {
        $info = Process::run(['pdfinfo', $path]);
        if (! $info->successful() || ! preg_match('/^Pages:\s+(\d+)/m', $info->output(), $match)) {
            throw new RuntimeException('pdfinfo не смог прочитать PDF: '.trim($info->errorOutput()));
        }
        $pages = (int) $match[1];

        $chunks = [];
        $letters = '';
        for ($page = 1; $page <= $pages; $page++) {
            $result = Process::run(['pdftotext', '-f', (string) $page, '-l', (string) $page, '-enc', 'UTF-8', $path, '-']);
            $text = $result->successful() ? $result->output() : '';
            $letters .= $text;

            foreach ($this->split($text) as $piece) {
                $chunks[] = [
                    'type' => 'text',
                    'page_start' => $page,
                    'page_end' => $page,
                    'headings' => [],
                    'text' => $piece,
                    'embed_text' => $piece,
                    'tokens' => (int) ceil(mb_strlen($piece) / 3.5),
                ];
            }
        }

        return [
            'sha256' => hash_file('sha256', $path),
            'pages' => $pages,
            'language' => self::language($letters),
            'chunks' => $chunks,
        ];
    }

    /**
     * Язык по тексту: у многих документов с русским названием текст английский.
     */
    public static function language(string $text): string
    {
        $cyrillic = preg_match_all('/[А-Яа-яЁё]/u', $text);
        $latin = preg_match_all('/[A-Za-z]/', $text);

        return $cyrillic > 0.3 * ($cyrillic + $latin) ? 'ru' : 'en';
    }

    /**
     * Абзацы страницы, собранные в куски до MAX_CHARS. Переносы строк внутри абзаца склеиваются.
     *
     * @return array<int, string>
     */
    private function split(string $text): array
    {
        $paragraphs = array_filter(array_map(
            fn (string $paragraph): string => trim((string) preg_replace('/\s+/u', ' ', $paragraph)),
            preg_split('/\n\s*\n/u', $text) ?: [],
        ), fn (string $paragraph): bool => mb_strlen($paragraph) > 2);

        $pieces = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            foreach (mb_str_split($paragraph, self::MAX_CHARS) as $part) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($part) + 2 > self::MAX_CHARS) {
                    $pieces[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $part : $current."\n\n".$part;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
