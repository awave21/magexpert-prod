<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Tool;
use App\Sender\Services\ListFields;

class ListFieldsTool implements Tool
{
    public function __construct(private readonly ListFields $fields) {}

    public function name(): string
    {
        return 'list_fields';
    }

    public function title(): string
    {
        return 'Поля подписчиков базы';
    }

    public function description(): string
    {
        return 'Дополнительные поля подписчиков базы (data) и их частые значения с количеством — из них собирается сегмент (field + values) '
            .'для count_segment и рассылки; tags — теги подписчиков с количеством (сегмент tags). Lists contact fields and frequent values of a list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['list' => Schemas::listRef()],
            'required' => ['list'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $list = $ctx->list($args['list']);

        return ['list' => ['id' => $list->id, 'name' => $list->name], 'fields' => $this->fields->of($list), 'tags' => $this->fields->tags($list)];
    }
}
