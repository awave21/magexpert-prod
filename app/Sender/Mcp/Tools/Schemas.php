<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\Support\BlockCompiler;

/**
 * Общие фрагменты JSON Schema для аргументов инструментов.
 */
class Schemas
{
    /**
     * @return array<string, mixed>
     */
    public static function templateRef(): array
    {
        return [
            'type' => ['integer', 'string'],
            'description' => 'Письмо: числовой ID или идентификатор (slug), например "event-announcement".',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listRef(): array
    {
        return [
            'type' => ['integer', 'string'],
            'description' => 'База подписчиков: числовой ID или название (см. list_lists).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function campaignRef(): array
    {
        return ['type' => 'integer', 'description' => 'ID рассылки (см. list_campaigns).'];
    }

    /**
     * Условие отбора подписчиков базы. Пустое — вся база.
     *
     * @return array<string, mixed>
     */
    public static function segment(): array
    {
        return [
            'type' => ['object', 'null'],
            'description' => 'Сегмент — часть базы. active_days: открывали письма за последние N дней; field + values: '
                .'дополнительное поле подписчика равно одному из значений (поля и значения — list_fields); tags: у подписчика есть хотя бы один из тегов (теги — list_fields, ключ tags). Условия складываются. Пусто или null — вся база.',
            'properties' => [
                'active_days' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 3650],
                'field' => ['type' => ['string', 'null'], 'pattern' => '^[a-z0-9_]{1,64}$'],
                'values' => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'maxLength' => 200], 'maxItems' => 50],
                'tags' => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'maxLength' => 120], 'maxItems' => 20],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Настройки рассылки, кроме названия.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function campaignSettings(): array
    {
        return [
            'template' => self::templateRef(),
            'list' => self::listRef(),
            'segment' => self::segment(),
            'track' => ['type' => 'boolean', 'description' => 'Считать открытия и переходы по ссылкам. По умолчанию да.'],
            'send_mode' => ['type' => 'string', 'enum' => ['now', 'drip'], 'description' => 'now — всем сразу после старта; drip — плавно, пачками.'],
            'batch_size' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 100000, 'description' => 'drip: писем в пачке.'],
            'batch_interval' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1440, 'description' => 'drip: минут между пачками.'],
            'daily_limit' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1000000, 'description' => 'drip: не больше писем в сутки.'],
            'window_start' => ['type' => ['string', 'null'], 'pattern' => '^\\d{2}:\\d{2}$', 'description' => 'drip: начало окна отправки, ЧЧ:ММ по Москве.'],
            'window_end' => ['type' => ['string', 'null'], 'pattern' => '^\\d{2}:\\d{2}$', 'description' => 'drip: конец окна отправки, ЧЧ:ММ по Москве.'],
            'window_days' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7], 'maxItems' => 7, 'description' => 'drip: дни недели отправки, 1 — понедельник … 7 — воскресенье.'],
        ];
    }

    /**
     * Поля содержимого письма: блоки визуального редактора или готовый HTML.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function content(): array
    {
        return [
            'blocks' => [
                'type' => 'array',
                'description' => 'Содержимое из блоков визуального редактора (см. get_block_schema). Сервер дополнит поля значениями по умолчанию, соберёт адаптивный HTML и текстовую версию; письмо откроется в визуальном редакторе. Взаимоисключается с html.',
                'items' => [
                    'type' => 'object',
                    'properties' => ['type' => ['type' => 'string', 'enum' => BlockCompiler::TYPES]],
                    'required' => ['type'],
                ],
                'maxItems' => BlockCompiler::MAX_BLOCKS,
            ],
            'settings' => [
                'type' => 'object',
                'description' => 'Оформление письма из блоков: width, bodyBg, outerBg, font, fontSize, textColor, linkColor, buttonBg и т. д. (см. get_block_schema → settings). Только вместе с blocks или для блочного письма.',
            ],
            'html' => [
                'type' => 'string',
                'description' => 'Готовый HTML письма (таблицы и встроенные стили). Взаимоисключается с blocks. Такое письмо правится в HTML-редакторе, не в визуальном.',
            ],
            'text' => [
                'type' => 'string',
                'description' => 'Текстовая версия письма. Если не передать, сервер соберёт её из HTML.',
            ],
        ];
    }
}
