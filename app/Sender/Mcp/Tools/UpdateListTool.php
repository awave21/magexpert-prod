<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\ContactList;

class UpdateListTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.lists.update';

    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'update_list';
    }

    public function title(): string
    {
        return 'Изменить базу';
    }

    public function description(): string
    {
        return 'Меняет название, описание или подтверждение подписки у базы. Передавайте только то, что меняется. Updates a subscriber list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list' => Schemas::listRef(),
                'name' => ['type' => 'string', 'maxLength' => 120],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 500],
                'require_confirmation' => ['type' => 'boolean'],
            ],
            'required' => ['list'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireDataEditing();
        $list = $ctx->list($args['list']);
        $changes = [];

        if (array_key_exists('name', $args)) {
            $name = trim((string) $args['name']);

            if ($name === '') {
                throw new ToolException('Название базы не может быть пустым');
            }

            if (CreateListTool::nameTaken($ctx, $name, $list->id)) {
                throw new ToolException("База «{$name}» уже есть");
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('description', $args)) {
            $changes['description'] = filled($args['description']) ? trim((string) $args['description']) : null;
        }

        if (array_key_exists('require_confirmation', $args)) {
            $changes['require_confirmation'] = (bool) $args['require_confirmation'];
        }

        if ($changes === []) {
            throw new ToolException('Нечего менять: передайте name, description или require_confirmation');
        }

        $list->update($changes);
        $ctx->audit(self::AUDIT_ACTION, $list->name);

        return [
            'message' => "База «{$list->name}» сохранена",
            'list' => $this->presenter->list($this->presenter->listsWithCounts(ContactList::query())->findOrFail($list->id)),
        ];
    }
}
