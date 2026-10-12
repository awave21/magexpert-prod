<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplatePresenter;
use App\Sender\Mcp\Support\TemplateWriter;
use App\Sender\Mcp\Tool;

class CreateTemplateTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.templates.store';

    public function __construct(
        private readonly TemplateWriter $writer,
        private readonly TemplatePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'create_template';
    }

    public function title(): string
    {
        return 'Создать письмо';
    }

    public function description(): string
    {
        return 'Создаёт новое письмо (шаблон) в организации. Содержимое — blocks (рекомендуется: письмо откроется в визуальном редакторе, '
            .'сервер соберёт адаптивный HTML) или готовый html. Идентификатор (slug) создаётся из названия, если не указан. '
            .'Папку можно указать названием — её создадут, если её нет. Перед созданием вызовите get_brand и get_block_schema, после — check_template. '
            .'Creates a letter from blocks or HTML.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Название письма в списке (видно только команде).'],
                'subject' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Тема письма, до 60 символов желательно. Можно с переменными {{ first_name }}.'],
                'slug' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$', 'description' => 'Идентификатор латиницей (уникален в организации). Если не указать — создаётся из названия.'],
                'preheader' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Прехедер: строка после темы в списке писем (40–90 символов).'],
                'folder' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Папка по названию; создаётся, если её нет.'],
                'folder_id' => ['type' => 'integer', 'description' => 'Папка по ID (вместо folder).'],
                'sender_address_id' => ['type' => 'integer', 'description' => 'ID адреса отправителя (см. get_brand → sender.addresses). Если не указать — адрес по умолчанию.'],
                'reply_to' => ['type' => 'string', 'format' => 'email', 'description' => 'Адрес для ответов.'],
            ], Schemas::content()),
            'required' => ['name', 'subject'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireContentEditing();

        $template = $this->writer->create($ctx, $args);
        $ctx->audit(self::AUDIT_ACTION, $template->name);

        return [
            'message' => "Письмо «{$template->name}» создано",
            'template' => $this->presenter->summary($template),
        ];
    }
}
