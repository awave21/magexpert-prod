<?php

namespace App\Sender\Services;

use App\Sender\Models\Template;

class TemplateRenderer
{
    /**
     * Подставляет переменные {{ name }} в тему, HTML и текст письма.
     * Блок {{#if name}}...{{/if}} выводится, если переменная заполнена (вложенность не поддерживается).
     * В HTML значения экранируются, отсутствующие переменные заменяются пустой строкой.
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, html: string, text: string|null}
     */
    public function render(Template $template, array $data): array
    {
        return [
            'subject' => $this->replace($template->subject, $data, false),
            'html' => $this->replace($template->body_html, $data, true),
            'text' => $template->body_text === null ? null : $this->replace($template->body_text, $data, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replace(string $source, array $data, bool $escape): string
    {
        $source = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}(.*?)\{\{\/if\}\}/s',
            fn (array $match): string => filled(data_get($data, $match[1])) && data_get($data, $match[1]) !== false ? $match[2] : '',
            $source,
        );

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $match) use ($data, $escape): string {
                $value = data_get($data, $match[1]);
                $value = is_scalar($value) ? (string) $value : '';

                return $escape ? e($value) : $value;
            },
            $source,
        );
    }
}
