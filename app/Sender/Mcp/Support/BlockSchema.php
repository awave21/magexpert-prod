<?php

namespace App\Sender\Mcp\Support;

/**
 * Описание модели письма из блоков для ассистента: типы блоков, их поля, допустимые значения и пример.
 * Значения по умолчанию берутся из BlockCompiler, который повторяет редактор (sender-ui/src/editor/model.ts).
 */
class BlockSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $common = [
            'id' => 'string, необязательно: сервер создаст сам',
            'padding' => 'object {t, r, b, l} в пикселях (или одно число на все стороны); у блоков внутри колонок боковые отступы всегда 0',
            'bg' => 'string: цвет фона блока (#RRGGBB) или пусто',
            'inset' => 'boolean: фон блока карточкой с полями письма по бокам, а не на всю ширину',
            'show' => 'enum: '.implode(' | ', BlockCompiler::SHOWS).' — где показывать блок',
            'cond' => 'string: имя переменной без скобок; блок попадает в письмо, только если она заполнена (пусто — всегда)',
        ];

        $types = [
            'heading' => ['label' => 'Заголовок', 'fields' => [
                'html' => 'string: простой HTML (<p>, <strong>, <em>, <a href>, <br>), переменные {{ name }}',
                'align' => 'enum: left | center | right',
                'size' => 'integer|null: размер шрифта, null — из settings.headingSize',
                'color' => 'string|null: цвет текста, null — settings.textColor',
            ]],
            'text' => ['label' => 'Текст', 'fields' => [
                'html' => 'string: абзацы <p>, списки <ul>/<ol>/<li>, <strong>, <em>, <a href>, <br>; стили для почты сервер добавит сам',
                'align' => 'enum: left | center | right',
                'size' => 'integer|null: размер шрифта, null — settings.fontSize',
                'color' => 'string|null',
            ]],
            'image' => ['label' => 'Картинка', 'fields' => [
                'src' => 'string, обязательно: https-адрес картинки',
                'alt' => 'string: описание картинки (обязательно для доступности)',
                'href' => 'string: ссылка по клику или пусто',
                'full' => 'boolean: на всю ширину содержимого',
                'width' => 'integer: ширина в пикселях, если full = false',
                'align' => 'enum: left | center | right',
                'radius' => 'integer: скругление углов',
            ]],
            'button' => ['label' => 'Кнопка', 'fields' => [
                'text' => 'string: надпись (простой текст)',
                'href' => 'string: https-ссылка или переменная, например {{ event_url }}',
                'fill' => 'string|null: цвет кнопки, null — settings.buttonBg',
                'color' => 'string: цвет надписи',
                'radius' => 'integer|null: скругление, null — settings.buttonRadius',
                'full' => 'boolean: на всю ширину',
                'align' => 'enum: left | center | right',
            ]],
            'divider' => ['label' => 'Разделитель', 'fields' => ['color' => 'string', 'thickness' => 'integer: толщина линии']],
            'spacer' => ['label' => 'Отступ', 'fields' => ['height' => 'integer: высота в пикселях']],
            'columns' => ['label' => 'Колонки', 'fields' => [
                'ratio' => 'enum: '.implode(' | ', BlockCompiler::RATIOS),
                'gap' => 'integer: расстояние между колонками',
                'columns' => 'array из двух массивов блоков [[...], [...]]; колонки внутрь колонок не вкладываются; на телефоне колонки встают друг под другом',
            ]],
            'social' => ['label' => 'Соцсети', 'fields' => [
                'align' => 'enum: left | center | right',
                'links' => 'array of {label: string, url: string}',
            ]],
            'footer' => ['label' => 'Подвал', 'fields' => [
                'html' => 'string: мелкий серый текст (почему пришло письмо, реквизиты, ссылка отписки {{ unsubscribe_url }})',
                'align' => 'enum: left | center | right',
            ]],
            'html' => ['label' => 'Свой HTML', 'fields' => [
                'html' => 'string: готовый фрагмент таблиц/HTML со встроенными стилями, вставляется в ячейку как есть',
            ]],
        ];

        foreach ($types as $type => &$info) {
            $defaults = BlockCompiler::defaults($type);
            unset($defaults['type']);
            $info['defaults'] = $defaults;
        }

        return [
            'format' => 'design = {version: 1, settings: {...}, blocks: [...]}. В инструментах create_template/update_template передайте blocks (массив блоков) и при необходимости settings — недостающие поля сервер заполнит значениями по умолчанию, соберёт HTML и текстовую версию.',
            'variables' => 'Переменные пишутся как {{ name }} в любом текстовом поле. Условный фрагмент: {{#if name}}...{{/if}}. Список доступных переменных — инструмент list_variables.',
            'limits' => 'Не больше '.BlockCompiler::MAX_BLOCKS.' блоков всего (вместе с блоками в колонках). Шрифты — только безопасные для почты (см. fonts).',
            'common_fields' => $common,
            'block_types' => $types,
            'settings' => [
                'fields' => [
                    'width' => 'integer 320–800: ширина письма (обычно 600)',
                    'bodyBg' => 'string: фон письма', 'outerBg' => 'string: фон вокруг письма', 'radius' => 'integer: скругление письма',
                    'font' => 'string: шрифт текста (значение из fonts)', 'fontSize' => 'integer', 'lineHeight' => 'number',
                    'textColor' => 'string', 'linkColor' => 'string',
                    'headingFont' => 'string: inherit или значение из fonts', 'headingSize' => 'integer', 'headingWeight' => 'integer',
                    'buttonBg' => 'string', 'buttonRadius' => 'integer',
                    'preheader' => 'string: не используется, прехедер задаётся полем preheader письма',
                ],
                'defaults' => BlockCompiler::DEFAULT_SETTINGS,
            ],
            'fonts' => BlockCompiler::FONTS,
            'example' => self::example(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'version' => 1,
            'settings' => ['buttonBg' => '#4C68EB', 'textColor' => '#1C1F27'],
            'blocks' => [
                ['type' => 'heading', 'html' => '<p>Приглашаем на вебинар</p>'],
                ['type' => 'text', 'html' => '<p>Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!</p><p>15 ноября в 10:00 разберём сложные клинические случаи.</p>'],
                ['type' => 'text', 'html' => '<p><strong>Дата:</strong> {{ start_date }}</p>', 'bg' => '#F4F6FA', 'inset' => true, 'cond' => 'start_date', 'padding' => ['t' => 18, 'r' => 24, 'b' => 18, 'l' => 24]],
                ['type' => 'button', 'text' => 'Зарегистрироваться', 'href' => '{{ event_url }}'],
                ['type' => 'divider'],
                ['type' => 'footer', 'html' => '<p>Вы получили письмо, потому что подписаны на новости.</p><p><a href="{{ unsubscribe_url }}">Отписаться</a></p>'],
            ],
        ];
    }
}
