<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplatePresenter;
use App\Sender\Mcp\Support\TemplateWriter;
use App\Sender\Mcp\Tool;

class UpdateTemplateTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.templates.update';

    public function __construct(
        private readonly TemplateWriter $writer,
        private readonly TemplatePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_template';
    }

    public function title(): string
    {
        return 'Изменить письмо';
    }

    public function description(): string
    {
        return 'Меняет существующее письмо: передайте только то, что меняется (тема, прехедер, название, папка, содержимое). '
            .'Содержимое заменяется целиком: blocks (письмо станет блочным) или html (письмо станет HTML-письмом). '
            .'Передайте expected_updated_at из get_template, чтобы не затереть правки, сделанные в редакторе. '
            .'Письма этим инструментом не удаляются. Если пользователь не просил перезаписать письмо — лучше создайте копию через create_template. '
            .'Updates a letter (partial).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'template' => Schemas::templateRef(),
                'expected_updated_at' => ['type' => 'string', 'description' => 'updated_at письма из get_template: если письмо с тех пор меняли, правка не применится.'],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'subject' => ['type' => 'string', 'maxLength' => 255],
                'slug' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$', 'description' => 'Осторожно: по идентификатору сайт находит служебные письма.'],
                'preheader' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'folder' => ['type' => ['string', 'null'], 'maxLength' => 120, 'description' => 'Папка по названию (создаётся, если нет); null или пустая строка — убрать из папки.'],
                'folder_id' => ['type' => 'integer'],
                'sender_address_id' => ['type' => ['integer', 'null']],
                'reply_to' => ['type' => ['string', 'null']],
            ], Schemas::content()),
            'required' => ['template'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireContentEditing();

        $template = $this->writer->update($ctx, $ctx->template($args['template']), $args);
        $ctx->audit(self::AUDIT_ACTION, $template->name);

        return [
            'message' => "Письмо «{$template->name}» сохранено",
            'template' => $this->presenter->summary($template),
        ];
    }
}
