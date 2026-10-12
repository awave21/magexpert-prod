<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\BlockSchema;
use App\Sender\Mcp\Tool;
use stdClass;

class GetBlockSchemaTool implements Tool
{
    public function name(): string
    {
        return 'get_block_schema';
    }

    public function title(): string
    {
        return 'Блоки визуального редактора';
    }

    public function description(): string
    {
        return 'Описание блоков визуального редактора писем: типы (heading, text, image, button, divider, spacer, columns, social, footer, html), '
            .'их поля, допустимые значения и значения по умолчанию, настройки оформления и пример письма. '
            .'Нужно, чтобы передавать blocks в create_template/update_template. Block types, fields, allowed values and an example document.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        return BlockSchema::describe();
    }
}
