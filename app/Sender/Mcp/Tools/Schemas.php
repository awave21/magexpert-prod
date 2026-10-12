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
