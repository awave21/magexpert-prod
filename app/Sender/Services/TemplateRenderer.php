<?php

namespace App\Sender\Services;

use App\Sender\Models\Template;

class TemplateRenderer
{
    /**
     * Подставляет переменные {{ name }} в тему, HTML и текст письма.
     * Блок {{#if name}}...{{/if}} выводится, если переменная заполнена; блоки можно вкладывать.
     * Блок {{#each items}}...{{/each}} повторяется для каждого элемента списка: внутри доступны поля элемента
     * и внешние переменные; пустой или отсутствующий список даёт пустую строку, блоки each вкладывать нельзя.
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
            'subject' => $this->renderSubject($template, $data),
            'html' => $html,
            'text' => $template->body_text === null ? null : $this->replace($template->body_text, $data, false),
        ];
    }

    /**
     * Только тема письма: для подготовки тысяч писем рассылки без сборки HTML.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderSubject(Template $template, array $data): string
    {
        return $this->replace($template->subject, $data, false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replace(string $source, array $data, bool $escape): string
    {
        // готовый текст элементов списка прячется за метками, чтобы {{ }} из данных не раскрывались повторно
        $rendered = [];

        $source = preg_replace_callback(
            '/\{\{#each\s+([a-zA-Z0-9_.]+)\s*\}\}(.*?)\{\{\/each\}\}/s',
            function (array $match) use ($data, $escape, &$rendered): string {
                $items = data_get($data, $match[1]);
                $output = '';

                foreach (is_array($items) ? array_values($items) : [] as $item) {
                    $scope = is_array($item) ? [...$data, ...$item] : [...$data, 'this' => $item];
                    $output .= $this->replace($match[2], $scope, $escape);
                }

                $token = '@@each_'.bin2hex(random_bytes(8)).'@@';
                $rendered[$token] = $output;

                return $token;
            },
            $source,
        );

        $source = $this->replaceConditionsAndVariables($source, $data, $escape);

        return $rendered === [] ? $source : strtr($source, $rendered);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceConditionsAndVariables(string $source, array $data, bool $escape): string
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
