<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\ContactList;

class CreateListTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.lists.store';

    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'create_list';
    }

    public function title(): string
    {
        return 'Создать базу';
    }

    public function description(): string
    {
        return 'Создаёт пустую базу подписчиков. Адреса добавляются через upsert_contacts. '
            .'require_confirmation=true — новым адресам уходит письмо со ссылкой подтверждения, рассылки они получают после подтверждения. '
            .'Creates a subscriber list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Название базы, уникальное в организации.'],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 500],
                'require_confirmation' => ['type' => 'boolean', 'description' => 'Подтверждать подписку письмом. По умолчанию нет.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireDataEditing();
        $name = trim((string) $args['name']);

        if ($name === '') {
            throw new ToolException('Укажите название базы');
        }

        if (self::nameTaken($ctx, $name)) {
            throw new ToolException("База «{$name}» уже есть");
        }

        $list = $ctx->organization->lists()->create([
            'name' => $name,
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'require_confirmation' => (bool) ($args['require_confirmation'] ?? false),
        ]);
        $ctx->audit(self::AUDIT_ACTION, $list->name);

        return [
            'message' => "База «{$list->name}» создана",
            'list' => $this->presenter->list($this->presenter->listsWithCounts(ContactList::query())->findOrFail($list->id)),
        ];
    }

    /**
     * Название занято другой базой организации (без учёта регистра).
     */
    public static function nameTaken(McpContext $ctx, string $name, ?int $exceptId = null): bool
    {
        return $ctx->organization->lists()->get()
            ->contains(fn (ContactList $l): bool => $l->id !== $exceptId && mb_strtolower(trim($l->name)) === mb_strtolower($name));
    }
}
