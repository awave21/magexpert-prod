<?php

namespace App\Sender\Services;

use App\Sender\Models\Template;

class TemplateRenderer
{
    /**
     * Подставляет переменные {{ name }} в тему, HTML и текст письма.
     * Блок {{#if name}}...{{/if}} выводится, если переменная заполнена; блоки можно вкладывать.
     * В HTML значения экранируются, отсутствующие переменные заменяются пустой строкой.
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, html: string, text: string|null}
     */
    public function render(Template $template, array $data): array
    {
        $html = $this->replace($template->body_html, $data, true);

        if (filled($template->preheader)) {
            $html = $this->withPreheader($html, $this->replace((string) $template->preheader, $data, true));
        }

        return [
            'subject' => $this->replace($template->subject, $data, false),
            'html' => $html,
            'text' => $template->body_text === null ? null : $this->replace($template->body_text, $data, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replace(string $source, array $data, bool $escape): string
    {
        // условия раскрываются изнутри наружу, поэтому блоки {{#if}} можно вкладывать друг в друга
        $pattern = '/\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}((?:(?!\{\{#if\s).)*?)\{\{\/if\}\}/s';

        do {
            $source = preg_replace_callback(
                $pattern,
                fn (array $match): string => filled(data_get($data, $match[1])) && data_get($data, $match[1]) !== false ? $match[2] : '',
                $source,
                -1,
                $count,
            );
        } while ($count > 0);

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

    /**
     * Прехедер: скрытая строка в начале письма, её почта показывает после темы в списке входящих.
     */
    private function withPreheader(string $html, string $preheader): string
    {
        $hidden = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all">'.$preheader
            .str_repeat('&#847;&zwnj;&nbsp;', 30).'</div>';

        if (preg_match('/<body[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $position = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $position).$hidden.substr($html, $position);
        }

        return $hidden.$html;
    }
}
