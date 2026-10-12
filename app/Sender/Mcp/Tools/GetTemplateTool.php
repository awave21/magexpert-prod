<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplatePresenter;
use App\Sender\Mcp\Tool;

class GetTemplateTool implements Tool
{
    public function __construct(private readonly TemplatePresenter $presenter) {}

    public function name(): string
    {
        return 'get_template';
    }

    public function title(): string
    {
        return 'Письмо целиком';
    }

    public function description(): string
    {
        return 'Одно письмо по ID или идентификатору: тема, прехедер, папка, отправитель, переменные, текстовая версия и содержимое — '
            .'блоки визуального редактора (design) для блочных писем или HTML. updated_at пригодится для безопасной правки (expected_updated_at в update_template). '
            .'Returns one letter with its blocks or HTML.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template' => Schemas::templateRef(),
                'include_html' => ['type' => 'boolean', 'default' => false, 'description' => 'Для блочного письма вернуть ещё и собранный HTML (по умолчанию только блоки). У HTML-писем HTML возвращается всегда.'],
            ],
            'required' => ['template'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        return $this->presenter->full($ctx->template($args['template']), (bool) ($args['include_html'] ?? false));
    }
}
