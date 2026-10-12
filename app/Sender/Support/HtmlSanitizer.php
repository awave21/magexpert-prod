<?php

namespace App\Sender\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Очистка HTML писем на сервере: письмо открывается в редакторе админки, поэтому в нём не должно быть
 * ничего исполняемого — скриптов, обработчиков on*, ссылок javascript: и т. п.
 *
 * Разбор — тем же алгоритмом HTML5, что у браузера (Dom\HTMLDocument). Элементы — по списку разрешённых:
 * опасные удаляются вместе с содержимым, неизвестные разворачиваются (остаётся их содержимое).
 * Если удалять нечего, возвращается исходная строка без изменений: переменные {{ … }}, условия {{#if}} между строками
 * таблицы и условные комментарии Outlook остаются как были.
 */
class HtmlSanitizer
{
    /**
     * Удаляются вместе с содержимым.
     */
    private const DROP = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param', 'link', 'base',
        'svg', 'math', 'noscript', 'template', 'noembed', 'noframes', 'xmp', 'plaintext', 'portal', 'fencedframe',
        'input', 'button', 'select', 'textarea', 'option', 'optgroup', 'datalist', 'keygen', 'output',
    ];

    /**
     * Обычная разметка писем.
     */
    private const ALLOW = [
        'html', 'head', 'body', 'title', 'style', 'meta',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'div', 'span', 'p', 'br', 'hr', 'a', 'img', 'picture', 'source', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup',
        'small', 'big', 'font', 'center', 'blockquote', 'pre', 'code', 'tt', 'kbd', 'samp', 'var', 'q', 'cite',
        'abbr', 'acronym', 'address', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 'figure',
        'figcaption', 'mark', 'wbr', 'label', 'bdi', 'bdo', 'time', 'nobr', 'details', 'summary',
    ];

    /**
     * Атрибуты со ссылками.
     */
    private const URL_ATTRIBUTES = ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster', 'data', 'codebase', 'cite', 'longdesc', 'lowsrc', 'dynsrc', 'ping'];

    private const SAFE_META_EQUIV = ['content-type', 'x-ua-compatible', 'content-language'];

    private const SCRIPT_SCHEMES = ['javascript', 'vbscript', 'livescript', 'mocha'];

    private bool $changed = false;

    /**
     * HTML письма целиком: полный документ (с <html>/<body>) или фрагмент.
     */
    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        return preg_match('/<(?:!doctype|html|head|body)[\s>\/]/i', $html) === 1 ? $this->document($html) : $this->fragment($html);
    }

    /**
     * Фрагмент: HTML блока визуального редактора или письма без <html>.
     */
    public function fragment(string $html): string
    {
        if (trim($html) === '' || ! $this->mayBeUnsafe($html)) {
            return $html;
        }

        $placeholders = [];
        $doc = HTMLDocument::createFromString('<!doctype html><html><head></head><body></body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        $body->innerHTML = $this->protect($html, $placeholders);
        $this->changed = false;
        $this->walk($body);

        return $this->changed ? $this->restore($body->innerHTML, $placeholders) : $html;
    }

    /**
     * Полный HTML-документ письма.
     */
    public function document(string $html): string
    {
        if (trim($html) === '' || ! $this->mayBeUnsafe($html)) {
            return $html;
        }

        $placeholders = [];
        $doc = HTMLDocument::createFromString($this->protect($html, $placeholders), LIBXML_NOERROR, 'UTF-8');
        $this->changed = false;

        if ($doc->documentElement !== null) {
            $this->walk($doc->documentElement);
        }

        return $this->changed ? $this->restore($doc->saveHtml(), $placeholders) : $html;
    }

    /**
     * Ссылка без исполняемой схемы: javascript:, vbscript:, data: (кроме картинок PNG/JPEG/GIF/WebP, если $image).
     * Относительные адреса и переменные {{ unsubscribe_url }} безопасны.
     */
    public function isSafeUrl(string $url, bool $image = false): bool
    {
        $value = strtolower((string) preg_replace('/[\x00-\x20\x7F\x{00A0}\x{FEFF}]+/u', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $value, $match) !== 1) {
            return true;
        }

        if (in_array($match[1], self::SCRIPT_SCHEMES, true)) {
            return false;
        }

        if ($match[1] === 'data') {
            return $image && preg_match('#^data:image/(png|jpe?g|gif|webp)[;,]#', $value) === 1;
        }

        return true;
    }

    public function url(string $url, bool $image = false): string
    {
        return $this->isSafeUrl($url, $image) ? $url : '';
    }

    /**
     * Значение для style (цвет, шрифт, размер): только буквы, цифры, пробелы и # % ( ) , . ' -,
     * без expression() и url(). Иначе — пустая строка.
     */
    public function cssValue(string $value): string
    {
        if (preg_match('/^[\p{L}0-9#%(),.\s\'\-]*$/u', $value) !== 1) {
            return '';
        }

        return $this->isDangerousCss($value) || str_contains(strtolower($value), 'url(') ? '' : $value;
    }

    /**
     * Блок визуального редактора (без обхода вложенных колонок): HTML, ссылки и цвета.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function block(array $block): array
    {
        if (isset($block['html']) && is_string($block['html'])) {
            $block['html'] = $this->fragment($block['html']);
        }

        foreach (['href' => false, 'src' => true] as $key => $image) {
            if (isset($block[$key]) && is_string($block[$key])) {
                $block[$key] = $this->url($block[$key], $image);
            }
        }

        foreach (['bg', 'color', 'fill'] as $key) {
            if (isset($block[$key]) && is_string($block[$key])) {
                $block[$key] = $this->cssValue($block[$key]);
            }
        }

        if (isset($block['layers']) && is_array($block['layers'])) {
            $block['layers'] = array_map(fn (mixed $layer): mixed => is_array($layer) ? $this->layer($layer) : $layer, $block['layers']);
        }

        if (isset($block['links']) && is_array($block['links'])) {
            $block['links'] = array_map(function (mixed $link): mixed {
                if (is_array($link) && isset($link['url']) && is_string($link['url'])) {
                    $link['url'] = $this->url($link['url']);
                }

                return $link;
            }, $block['links']);
        }

        return $block;
    }

    /**
     * Слой зеро-блока: цвета попадают в style, адрес фото — в src картинки.
     *
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private function layer(array $layer): array
    {
        foreach (['fill', 'fill2', 'stroke', 'shadow', 'color', 'c1', 'c2', 'c3'] as $key) {
            if (isset($layer[$key]) && is_string($layer[$key])) {
                $layer[$key] = $this->cssValue($layer[$key]);
            }
        }

        if (isset($layer['src']) && is_string($layer['src'])) {
            $layer['src'] = $this->url($layer['src'], true);
        }

        return $layer;
    }

    /**
     * Блоки письма из редактора, включая блоки внутри колонок.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return array<int|string, mixed>
     */
    public function blocks(array $blocks): array
    {
        return array_map(function (mixed $block): mixed {
            if (! is_array($block)) {
                return $block;
            }

            $block = $this->block($block);

            if (isset($block['columns']) && is_array($block['columns'])) {
                $block['columns'] = array_map(fn (mixed $column): mixed => is_array($column) ? $this->blocks($column) : $column, $block['columns']);
            }

            return $block;
        }, $blocks);
    }

    /**
     * Настройки оформления письма: строковые значения попадают в style, кроме прехедера.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function settings(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (is_string($value) && $key !== 'preheader') {
                $settings[$key] = $this->cssValue($value);
            }
        }

        return $settings;
    }

    /**
     * design письма из блоков: {version, settings, blocks}.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function design(array $design): array
    {
        if (isset($design['blocks']) && is_array($design['blocks'])) {
            $design['blocks'] = $this->blocks($design['blocks']);
        }

        if (isset($design['settings']) && is_array($design['settings'])) {
            $design['settings'] = $this->settings($design['settings']);
        }

        return $design;
    }

    /**
     * Быстрая проверка: без «<» в строке нет ни элементов, ни атрибутов.
     */
    private function mayBeUnsafe(string $html): bool
    {
        return str_contains($html, '<');
    }

    private function walk(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof Element) {
                continue;
            }

            $name = strtolower($node->localName);

            if (in_array($name, self::DROP, true) || ($name === 'meta' && ! $this->isSafeMeta($node))) {
                $node->remove();
                $this->changed = true;

                continue;
            }

            $this->cleanAttributes($node, $name);

            if ($name === 'style') {
                $this->cleanStyleElement($node);

                continue;
            }

            $this->walk($node);

            // неизвестный элемент разворачивается: остаётся содержимое, офисные теги вида o:p не трогаем
            if (! in_array($name, self::ALLOW, true) && ! str_contains($name, ':')) {
                $node->replaceWith(...iterator_to_array($node->childNodes));
                $this->changed = true;
            }
        }
    }

    private function cleanAttributes(Element $element, string $name): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attr = strtolower($attribute->name);
            $value = (string) $attribute->value;
            $unsafe = str_starts_with($attr, 'on')
                || in_array($attr, ['srcdoc', 'formaction', 'action'], true)
                || (in_array($attr, self::URL_ATTRIBUTES, true) && ! $this->isSafeUrl($value, $name === 'img' || $attr === 'background' || $attr === 'poster'))
                || ($attr === 'style' && $this->isDangerousCss($value));

            if ($unsafe) {
                $element->removeAttributeNode($attribute);
                $this->changed = true;
            }
        }
    }

    private function isSafeMeta(Element $meta): bool
    {
        $equiv = $meta->getAttribute('http-equiv');

        return $equiv === null || in_array(strtolower(trim($equiv)), self::SAFE_META_EQUIV, true);
    }

    /**
     * В <style> письма убираются только опасные конструкции, остальная вёрстка остаётся.
     */
    private function cleanStyleElement(Element $style): void
    {
        $css = (string) $style->textContent;

        if (! $this->isDangerousCss($css)) {
            return;
        }

        $cleaned = (string) preg_replace(
            ['/expression\s*\(/i', '/(?:java|vb)script\s*:/i', '/-moz-binding\s*:[^;}]*/i', '/url\s*\(\s*([\'"]?)\s*data:(?!image\/(?:png|jpe?g|gif|webp)[;,])[^)]*\)/i'],
            ['x(', '', '', 'none'],
            $css,
        );

        if ($this->isDangerousCss($cleaned)) {
            $style->remove();
        } else {
            $style->textContent = $cleaned;
        }

        $this->changed = true;
    }

    /**
     * expression(), javascript:/vbscript:, -moz-binding, behavior (кроме VML #default#) и url() с исполняемой схемой.
     * Перед проверкой убираются комментарии, экранирование CSS и пробелы.
     */
    private function isDangerousCss(string $css): bool
    {
        $normalized = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $normalized = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', fn (array $m): string => mb_chr((int) hexdec($m[1])) ?: '', $normalized);
        $normalized = (string) preg_replace('/\\\\(.)/s', '$1', $normalized);
        $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $normalized));

        if (str_contains($normalized, 'expression(') || str_contains($normalized, 'javascript:') || str_contains($normalized, 'vbscript:')
            || str_contains($normalized, '-moz-binding')) {
            return true;
        }

        if (preg_match_all('/behavior:([^;}]*)/', $normalized, $behaviors) > 0) {
            foreach ($behaviors[1] as $behavior) {
                if (! str_starts_with(trim($behavior, '\'"'), 'url(#default#') && $behavior !== 'none') {
                    return true;
                }
            }
        }

        preg_match_all('/url\(([\'"]?)(.*?)\1\)/', $normalized, $urls);

        foreach ($urls[2] as $url) {
            if (! $this->isSafeUrl($url, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Условия {{#if x}} и {{/if}} могут стоять между строками таблицы: парсер вынес бы такой текст из таблицы.
     * На время разбора они заменяются инструкциями обработки, которые парсер оставляет на месте (как комментарии).
     *
     * @param  array<string, string>  $placeholders
     */
    private function protect(string $html, array &$placeholders): string
    {
        return (string) preg_replace_callback('/\{\{\s*(?:#if\s+[a-zA-Z0-9_.]+|\/if)\s*\}\}/', function (array $match) use (&$placeholders): string {
            $key = 'sndr'.count($placeholders).'x'.bin2hex(random_bytes(4));
            $placeholders[$key] = $match[0];

            return '<?'.$key.'?>';
        }, $html);
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function restore(string $html, array $placeholders): string
    {
        foreach ($placeholders as $key => $original) {
            $html = str_replace(['<!--?'.$key.'?-->', '<?'.$key.'?>', '&lt;?'.$key.'?&gt;'], $original, $html);
        }

        return $html;
    }
}
