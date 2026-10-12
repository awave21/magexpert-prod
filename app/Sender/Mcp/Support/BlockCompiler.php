<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Mcp\ToolException;
use App\Sender\Support\HtmlSanitizer;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Str;

/**
 * Письмо из блоков на сервере: нормализует design так же, как редактор (sender-ui/src/editor/model.ts),
 * и собирает из него HTML тем же способом, что sender-ui/src/editor/render.ts.
 * Так письмо, созданное ассистентом, открывается в визуальном редакторе и выглядит так же.
 */
class BlockCompiler
{
    public const TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'columns', 'social', 'footer', 'html'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const SHOWS = ['all', 'desktop', 'mobile'];

    public const RATIOS = ['50-50', '33-67', '67-33'];

    public const MAX_BLOCKS = 200;

    public const FONTS = [
        'Arial' => 'Arial, Helvetica, sans-serif',
        'Helvetica' => 'Helvetica, Arial, sans-serif',
        'Verdana' => 'Verdana, Geneva, sans-serif',
        'Trebuchet' => "'Trebuchet MS', Arial, sans-serif",
        'Georgia' => 'Georgia, Times, serif',
        'Times' => "'Times New Roman', Times, serif",
    ];

    public const DEFAULT_SETTINGS = [
        'width' => 600,
        'bodyBg' => '#FFFFFF',
        'outerBg' => '#EEF1F6',
        'radius' => 8,
        'font' => 'Arial, Helvetica, sans-serif',
        'fontSize' => 16,
        'lineHeight' => 1.6,
        'textColor' => '#1C1F27',
        'linkColor' => '#3B51D3',
        'headingFont' => 'inherit',
        'headingSize' => 26,
        'headingWeight' => 700,
        'buttonBg' => '#4C68EB',
        'buttonRadius' => 10,
        'preheader' => '',
    ];

    /**
     * @var list<string>
     */
    private array $storedEmptyImages = [];

    public function __construct(private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer) {}

    /**
     * Значения по умолчанию для каждого типа блока (как createBlock в редакторе), без id.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $type): array
    {
        $base = fn (int $t, int $r, int $b, int $l): array => [
            'padding' => ['t' => $t, 'r' => $r, 'b' => $b, 'l' => $l], 'bg' => '', 'inset' => false, 'show' => 'all', 'cond' => '',
        ];

        return match ($type) {
            'heading' => [...$base(20, 40, 8, 40), 'type' => 'heading', 'html' => '<p>Заголовок письма</p>', 'align' => 'left', 'size' => null, 'color' => null],
            'text' => [...$base(12, 40, 12, 40), 'type' => 'text', 'html' => '<p>Текст письма.</p>', 'align' => 'left', 'size' => null, 'color' => null],
            'image' => [...$base(8, 40, 8, 40), 'type' => 'image', 'src' => '', 'alt' => '', 'href' => '', 'full' => true, 'width' => 300, 'align' => 'center', 'radius' => 8, 'name' => '', 'meta' => ''],
            'button' => [...$base(12, 40, 20, 40), 'type' => 'button', 'text' => 'Подробнее', 'href' => 'https://', 'fill' => null, 'color' => '#FFFFFF', 'radius' => null, 'full' => false, 'align' => 'center'],
            'divider' => [...$base(8, 40, 8, 40), 'type' => 'divider', 'color' => '#DEE1E8', 'thickness' => 1],
            'spacer' => [...$base(0, 0, 0, 0), 'type' => 'spacer', 'height' => 24],
            'columns' => [...$base(12, 40, 12, 40), 'type' => 'columns', 'ratio' => '50-50', 'gap' => 16, 'columns' => [[], []]],
            'social' => [...$base(12, 40, 12, 40), 'type' => 'social', 'align' => 'center', 'links' => []],
            'footer' => [...$base(16, 40, 28, 40), 'type' => 'footer', 'align' => 'left', 'html' => '<p>Вы получили письмо, потому что зарегистрировались на сайте.</p>'],
            'html' => [...$base(0, 0, 0, 0), 'type' => 'html', 'html' => ''],
        };
    }

    /**
     * Проверяет блоки ассистента и дополняет их значениями по умолчанию: получается design, который понимает редактор.
     *
     * HTML блоков очищается от скриптов и обработчиков, ссылки — от javascript: (см. HtmlSanitizer).
     * Картинки без адреса — ошибка, кроме уже сохранённых в письме заготовок (их id в $storedEmptyImages):
     * редактор создаёт такие блоки, а при сборке HTML они пропускаются.
     *
     * @param  array<int, mixed>  $blocks
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $storedEmptyImages
     * @return array{version: int, settings: array<string, mixed>, blocks: list<array<string, mixed>>}
     *
     * @throws ToolException
     */
    public function normalize(array $blocks, array $settings = [], array $storedEmptyImages = []): array
    {
        $this->storedEmptyImages = $storedEmptyImages;
        $errors = [];
        $normalizedSettings = self::DEFAULT_SETTINGS;

        foreach ($settings as $key => $value) {
            if (! array_key_exists($key, self::DEFAULT_SETTINGS)) {
                $errors[] = "settings.{$key}: такой настройки нет. Доступны: ".implode(', ', array_keys(self::DEFAULT_SETTINGS));

                continue;
            }

            if ($value === null) {
                continue;
            }

            $default = self::DEFAULT_SETTINGS[$key];
            $normalizedSettings[$key] = match (true) {
                is_int($default) => is_numeric($value) ? (int) round((float) $value) : $this->fail($errors, "settings.{$key}: нужно число", $default),
                is_float($default) => is_numeric($value) ? (float) $value : $this->fail($errors, "settings.{$key}: нужно число", $default),
                default => is_scalar($value) ? (string) $value : $this->fail($errors, "settings.{$key}: нужна строка", $default),
            };
        }

        // недопустимое значение для style заменяется значением по умолчанию
        foreach ($this->sanitizer->settings($normalizedSettings) as $key => $value) {
            $normalizedSettings[$key] = $value === '' && is_string($normalizedSettings[$key]) && $normalizedSettings[$key] !== '' ? self::DEFAULT_SETTINGS[$key] : $value;
        }
        $normalizedSettings['width'] = max(320, min(800, (int) $normalizedSettings['width']));

        $count = 0;
        $list = [];

        foreach (array_values($blocks) as $index => $block) {
            $normalized = $this->block($block, "blocks[{$index}]", false, $errors, $count);

            if ($normalized !== null) {
                $list[] = $normalized;
            }
        }

        if ($count > self::MAX_BLOCKS) {
            $errors[] = 'В письме слишком много блоков (больше '.self::MAX_BLOCKS.')';
        }

        if ($errors !== []) {
            throw new ToolException('Блоки письма заполнены с ошибками: '.implode('; ', array_slice($errors, 0, 15)), ['blocks' => array_values($errors)]);
        }

        return ['version' => 1, 'settings' => $normalizedSettings, 'blocks' => $list];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function block(mixed $block, string $path, bool $nested, array &$errors, int &$count): ?array
    {
        $count++;

        if (! is_array($block) || array_is_list($block) && $block !== []) {
            $errors[] = "{$path}: блок должен быть объектом";

            return null;
        }

        $type = $block['type'] ?? null;

        if (! in_array($type, self::TYPES, true)) {
            $errors[] = "{$path}.type: неизвестный тип блока. Доступны: ".implode(', ', self::TYPES);

            return null;
        }

        if ($nested && $type === 'columns') {
            $errors[] = "{$path}: колонки нельзя вкладывать в колонки";

            return null;
        }

        $defaults = self::defaults($type);
        $out = ['id' => is_string($block['id'] ?? null) && $block['id'] !== '' ? Str::limit($block['id'], 32, '') : Str::lower(Str::random(8))];

        foreach ($defaults as $key => $default) {
            $value = $block[$key] ?? null;

            if ($key === 'type') {
                $out['type'] = $type;

                continue;
            }

            if ($value === null) {
                $out[$key] = $default;

                continue;
            }

            $out[$key] = match ($key) {
                'padding' => $this->padding($value, "{$path}.padding", $errors, $default),
                'align' => $this->enum($value, self::ALIGNS, "{$path}.align", $errors, $default),
                'show' => $this->enum($value, self::SHOWS, "{$path}.show", $errors, $default),
                'ratio' => $this->enum($value, self::RATIOS, "{$path}.ratio", $errors, $default),
                'columns' => $this->columns($value, $path, $errors, $count),
                'links' => $this->links($value, "{$path}.links", $errors),
                'inset', 'full' => (bool) $value,
                'size', 'radius', 'width', 'thickness', 'height', 'gap' => is_numeric($value)
                    ? max(0, (int) round((float) $value))
                    : $this->fail($errors, "{$path}.{$key}: нужно число", $default),
                default => is_scalar($value) ? (string) $value : $this->fail($errors, "{$path}.{$key}: нужна строка", $default),
            };
        }

        if ($out['cond'] !== '' && ! preg_match('/^[a-zA-Z0-9_.]+$/', $out['cond'])) {
            $errors[] = "{$path}.cond: имя переменной без фигурных скобок, например first_name";
        }

        $out = $this->sanitizer->block($out);

        if ($type === 'image' && $out['src'] === '' && ! in_array($out['id'], $this->storedEmptyImages, true)) {
            $errors[] = "{$path}.src: у картинки нет адреса (https://…)";
        }

        // у блоков внутри колонки нет своих боковых отступов: их задаёт сама колонка
        if ($nested) {
            $out['padding']['l'] = 0;
            $out['padding']['r'] = 0;
        }

        return $out;
    }

    /**
     * id картинок без адреса в сохранённом design, включая блоки внутри колонок.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return list<string>
     */
    public static function emptyImageIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'image' && ($block['src'] ?? '') === '' && is_string($block['id'] ?? null)) {
                $ids[] = $block['id'];
            }

            foreach (is_array($block['columns'] ?? null) ? $block['columns'] : [] as $column) {
                $ids = [...$ids, ...(is_array($column) ? self::emptyImageIds($column) : [])];
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $errors
     * @param  array{t: int, r: int, b: int, l: int}  $default
     * @return array{t: int, r: int, b: int, l: int}
     */
    private function padding(mixed $value, string $path, array &$errors, array $default): array
    {
        if (is_numeric($value)) {
            $v = max(0, (int) $value);

            return ['t' => $v, 'r' => $v, 'b' => $v, 'l' => $v];
        }

        if (! is_array($value)) {
            $errors[] = "{$path}: нужен объект {t, r, b, l} в пикселях";

            return $default;
        }

        $out = $default;

        foreach (['t', 'r', 'b', 'l'] as $side) {
            if (isset($value[$side]) && is_numeric($value[$side])) {
                $out[$side] = max(0, (int) $value[$side]);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private function enum(mixed $value, array $allowed, string $path, array &$errors, string $default): string
    {
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $errors[] = "{$path}: допустимо ".implode(', ', $allowed);

        return $default;
    }

    /**
     * @param  list<string>  $errors
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function columns(mixed $value, string $path, array &$errors, int &$count): array
    {
        if (! is_array($value) || count($value) !== 2 || ! is_array($value[0] ?? null) || ! is_array($value[1] ?? null)) {
            $errors[] = "{$path}.columns: нужен массив из двух колонок, каждая — массив блоков";

            return [[], []];
        }

        $out = [[], []];

        foreach ([0, 1] as $col) {
            foreach (array_values($value[$col]) as $i => $child) {
                $normalized = $this->block($child, "{$path}.columns[{$col}][{$i}]", true, $errors, $count);

                if ($normalized !== null) {
                    $out[$col][] = $normalized;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{label: string, url: string}>
     */
    private function links(mixed $value, string $path, array &$errors): array
    {
        if (! is_array($value)) {
            $errors[] = "{$path}: нужен массив ссылок {label, url}";

            return [];
        }

        return array_values(array_map(fn (mixed $link): array => [
            'label' => is_array($link) && is_scalar($link['label'] ?? null) ? (string) $link['label'] : '',
            'url' => is_array($link) && is_scalar($link['url'] ?? null) ? (string) $link['url'] : '',
        ], $value));
    }

    /**
     * @param  list<string>  $errors
     */
    private function fail(array &$errors, string $message, mixed $default): mixed
    {
        $errors[] = $message;

        return $default;
    }

    // ---------- сборка HTML (порт render.ts) ----------

    /**
     * @param  array{settings: array<string, mixed>, blocks: list<array<string, mixed>>}  $design  нормализованный design
     */
    public function toHtml(array $design, string $title = ''): string
    {
        $s = $design['settings'];
        $rows = implode("\n", array_map(fn (array $b): string => $this->renderBlock($b, $s, (int) $s['width']), $design['blocks']));
        $width = (int) $s['width'];
        $outer = $s['outerBg'];

        return '<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>'.$this->text($title).'</title>
<style>
body{margin:0;padding:0;-webkit-text-size-adjust:100%}
table{border-collapse:collapse}
img{-ms-interpolation-mode:bicubic}
.hide-desktop{display:none;max-height:0;overflow:hidden;mso-hide:all}
@media only screen and (max-width:'.($width + 20).'px){
  .container{width:100%!important;max-width:100%!important}
  .col{display:block!important;width:100%!important;padding-left:0!important;padding-right:0!important}
  .px{padding-left:20px!important;padding-right:20px!important}
  .hide-mobile{display:none!important}
  .hide-desktop{display:table-cell!important;max-height:none!important;overflow:visible!important}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:'.$outer.'">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$outer.'" style="background-color:'.$outer.'">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" class="container" width="'.$width.'" cellpadding="0" cellspacing="0" border="0" style="width:'.$width.'px;max-width:'.$width.'px;background-color:'.$s['bodyBg'].';border-radius:'.$s['radius'].'px;overflow:hidden">
'.$rows.'
</table>
</td></tr>
</table>
</body>
</html>';
    }

    /**
     * Текстовая версия письма (как htmlToText в редакторе): ссылки в скобках, пункты списков с точкой.
     */
    public function htmlToText(string $html): string
    {
        $doc = HTMLDocument::createFromString($html === '' ? '<p></p>' : $html, LIBXML_NOERROR);

        foreach ($doc->querySelectorAll('style,title,head,script') as $el) {
            $el->remove();
        }

        foreach ($doc->querySelectorAll('div[style*="display:none"]') as $el) {
            $el->remove();
        }

        foreach ($doc->querySelectorAll('a') as $a) {
            $href = $a->getAttribute('href');

            if ($href !== null && $href !== '' && $href !== trim((string) $a->textContent)) {
                $a->append(" ({$href})");
            }
        }

        foreach ($doc->querySelectorAll('br') as $br) {
            $br->replaceWith("\n");
        }

        foreach ($doc->querySelectorAll('p,div,h1,h2,h3,li,tr') as $el) {
            $el->append("\n");
        }

        foreach ($doc->querySelectorAll('li') as $li) {
            $li->prepend('• ');
        }

        $text = str_replace("\u{00A0}", ' ', (string) ($doc->body?->textContent ?? ''));
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array{color: string, size: int, align: string, margin?: int}  $opts
     */
    private function inlineRichText(string $html, array $s, array $opts): string
    {
        $doc = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);
        $root = $doc->body?->firstElementChild;

        if (! $root instanceof Element) {
            return '';
        }

        $margin = $opts['margin'] ?? (int) round($opts['size'] * 0.75);

        foreach ($root->querySelectorAll('p') as $el) {
            $own = $el->getAttribute('style') ?? '';
            $el->setAttribute('style', "margin:0 0 {$margin}px;{$own}");
        }

        foreach ($root->querySelectorAll('ul,ol') as $el) {
            $el->setAttribute('style', "margin:0 0 {$margin}px;padding-left:24px");
        }

        foreach ($root->querySelectorAll('li') as $el) {
            $el->setAttribute('style', 'margin:0 0 4px');
        }

        foreach ($root->querySelectorAll('li > p') as $el) {
            $el->setAttribute('style', 'margin:0');
        }

        foreach ($root->querySelectorAll('a') as $el) {
            $el->setAttribute('style', "color:{$s['linkColor']};text-decoration:underline");
            $el->setAttribute('target', '_blank');
        }

        // у последнего абзаца нет нижнего отступа
        $last = $root->lastElementChild;

        if ($last !== null && in_array(strtoupper($last->tagName), ['P', 'UL', 'OL'], true)) {
            $last->setAttribute('style', rtrim((string) $last->getAttribute('style'), ';').';margin-bottom:0');
        }

        return $root->innerHTML;
    }

    /**
     * @param  array<string, mixed>  $b
     */
    private function cellTop(array $b, string $inner, string $extra = '', bool $top = true): string
    {
        $bg = $b['bg'] !== '' ? "background-color:{$b['bg']};" : '';
        $class = ($top ? 'px' : '').$this->showClass($b);
        $pad = $this->padCss($b['padding']);
        $row = $b['bg'] !== '' && $b['inset']
            ? '<tr><td class="'.$class.'" style="padding:0 '.($top ? 40 : 0).'px"><div style="'.$pad.';'.$bg.$extra.'">'.$inner.'</div></td></tr>'
            : '<tr><td class="'.$class.'" style="'.$pad.';'.$bg.$extra.'">'.$inner.'</td></tr>';

        return $b['cond'] !== '' ? '{{#if '.$b['cond'].'}}'.$row.'{{/if}}' : $row;
    }

    // выравнивание задаёт ячейка, а не align у таблицы: таблица с align="left" «плавает»
    private function aligned(string $align, string $inner, bool $full = false): string
    {
        $margin = $align === 'center' ? '0 auto' : ($align === 'right' ? '0 0 0 auto' : '0 auto 0 0');

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="'.$align.'"><table role="presentation" cellpadding="0" cellspacing="0" border="0" '.($full ? 'width="100%"' : '').' style="margin:'.$margin.'">'.$inner.'</table></td></tr></table>';
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $s
     */
    private function renderBlock(array $b, array $s, int $width, bool $top = true): string
    {
        $cell = fn (string $inner, string $extra = ''): string => $this->cellTop($b, $inner, $extra, $top);
        $contentWidth = max(0, $width - $b['padding']['l'] - $b['padding']['r']);

        switch ($b['type']) {
            case 'heading':
                $size = $b['size'] ?? $s['headingSize'];
                $font = $s['headingFont'] === 'inherit' ? $s['font'] : $s['headingFont'];
                $color = $b['color'] ?? $s['textColor'];
                $inner = $this->inlineRichText($b['html'], $s, ['color' => $color, 'size' => (int) $size, 'align' => $b['align'], 'margin' => 6]);

                return $cell("<div style=\"margin:0;font-family:{$font};font-size:{$size}px;line-height:1.25;font-weight:{$s['headingWeight']};color:{$color};text-align:{$b['align']}\">{$inner}</div>");
            case 'text':
                $size = $b['size'] ?? $s['fontSize'];
                $color = $b['color'] ?? $s['textColor'];
                $inner = $this->inlineRichText($b['html'], $s, ['color' => $color, 'size' => (int) $size, 'align' => $b['align']]);

                return $cell("<div style=\"font-family:{$s['font']};font-size:{$size}px;line-height:{$s['lineHeight']};color:{$color};text-align:{$b['align']}\">{$inner}</div>");
            case 'footer':
                $inner = $this->inlineRichText($b['html'], $s, ['color' => '#636976', 'size' => 12, 'align' => $b['align'], 'margin' => 6]);

                return $cell("<div style=\"font-family:{$s['font']};font-size:12px;line-height:1.55;color:#636976;text-align:{$b['align']}\">{$inner}</div>");
            case 'image':
                if ($b['src'] === '') {
                    return '';
                }

                $w = $b['full'] ? $contentWidth : min($b['width'], $contentWidth);
                $img = '<img src="'.$this->attr($b['src']).'" alt="'.$this->attr($b['alt']).'" width="'.$w.'" style="display:block;width:100%;max-width:'.$w.'px;height:auto;border:0;outline:none;text-decoration:none;border-radius:'.$b['radius'].'px">';
                $linked = $b['href'] !== '' ? '<a href="'.$this->attr($b['href']).'" target="_blank" style="text-decoration:none">'.$img.'</a>' : $img;

                return $cell($this->aligned($b['align'], "<tr><td>{$linked}</td></tr>"));
            case 'button':
                $bg = $b['fill'] ?? $s['buttonBg'];
                $radius = $b['radius'] ?? $s['buttonRadius'];
                $a = '<a href="'.$this->attr($b['href']).'" target="_blank" style="display:'.($b['full'] ? 'block' : 'inline-block').';padding:14px 28px;font-family:'.$s['font'].';font-size:15px;font-weight:600;line-height:1.2;color:'.$b['color'].';text-decoration:none;text-align:center;border-radius:'.$radius.'px">'.$this->text($b['text']).'</a>';

                return $cell($this->aligned($b['align'], '<tr><td align="center" bgcolor="'.$bg.'" style="background-color:'.$bg.';border-radius:'.$radius.'px">'.$a.'</td></tr>', $b['full']));
            case 'divider':
                return $cell("<div style=\"height:{$b['thickness']}px;line-height:{$b['thickness']}px;font-size:0;background-color:{$b['color']}\">&nbsp;</div>");
            case 'spacer':
                return $cell("<div style=\"height:{$b['height']}px;line-height:{$b['height']}px;font-size:0\">&nbsp;</div>");
            case 'social':
                $links = implode('', array_map(
                    fn (array $l): string => '<a href="'.$this->attr($l['url']).'" target="_blank" style="display:inline-block;margin:0 4px 6px;padding:7px 14px;border-radius:999px;background-color:#EDF0F6;color:'.$s['textColor'].';font-family:'.$s['font'].';font-size:13px;font-weight:600;text-decoration:none">'.$this->text($l['label']).'</a>',
                    array_filter($b['links'], fn (array $l): bool => $l['url'] !== ''),
                ));

                return $cell($links, "text-align:{$b['align']}");
            case 'html':
                return $cell($b['html']);
            case 'columns':
                [$l, $r] = match ($b['ratio']) {
                    '33-67' => [33, 67],
                    '67-33' => [67, 33],
                    default => [50, 50],
                };
                $gap = (int) $b['gap'];
                $lw = (int) round(($contentWidth - $gap) * $l / 100);
                $rw = $contentWidth - $gap - $lw;
                $col = fn (array $blocks, int $w, int $pct, string $padStyle): string => '<td class="col" width="'.$pct.'%" valign="top" style="width:'.$w.'px;'.$padStyle.'"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                    .implode('', array_map(fn (array $x): string => $this->renderBlock($x, $s, $w, false), $blocks)).'</table></td>';
                $half = $gap / 2;
                $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                    .$col($b['columns'][0], $lw, $l, "padding-right:{$half}px").$col($b['columns'][1], $rw, $r, "padding-left:{$half}px").'</tr></table>';

                return $cell($inner);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $b
     */
    private function showClass(array $b): string
    {
        return match ($b['show']) {
            'desktop' => ' hide-mobile',
            'mobile' => ' hide-desktop',
            default => '',
        };
    }

    /**
     * @param  array{t: int, r: int, b: int, l: int}  $p
     */
    private function padCss(array $p): string
    {
        return "padding:{$p['t']}px {$p['r']}px {$p['b']}px {$p['l']}px";
    }

    private function attr(string $value): string
    {
        return str_replace(['&', '"', '<'], ['&amp;', '&quot;', '&lt;'], $value);
    }

    private function text(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
