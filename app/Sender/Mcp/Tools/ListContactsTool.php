<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Models\Contact;

class ListContactsTool implements Tool
{
    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'list_contacts';
    }

    public function title(): string
    {
        return 'Подписчики базы';
    }

    public function description(): string
    {
        return 'Подписчики базы, новые первыми: адрес, имя, дополнительные поля (data, они же переменные в письме), статус '
            .'(subscribed, pending — ждёт подтверждения, unsubscribed). Поиск по адресу и имени, фильтр по статусу, постранично. '
            .'Lists contacts of a subscriber list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list' => Schemas::listRef(),
                'query' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Часть адреса или имени.'],
                'status' => ['type' => 'string', 'enum' => ['subscribed', 'pending', 'unsubscribed']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
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
        $query = $list->contacts()->latest('id');

        if (filled($args['query'] ?? null)) {
            $raw = trim((string) $args['query']);
            $like = fn (string $value): string => '%'.str_replace(['%', '_'], ['\%', '\_'], $value).'%';
            $query->where(function ($w) use ($raw, $like): void {
                $w->where('email', 'like', $like(mb_strtolower($raw)));

                foreach (array_unique([$raw, mb_strtolower($raw), mb_convert_case($raw, MB_CASE_TITLE)]) as $variant) {
                    $w->orWhere('name', 'like', $like($variant));
                }
            });
        }

        match ($args['status'] ?? null) {
            'subscribed' => $query->subscribed(),
            'pending' => $query->whereNull('unsubscribed_at')->pending(),
            'unsubscribed' => $query->whereNotNull('unsubscribed_at'),
            default => null,
        };

        $total = (clone $query)->count();
        $limit = (int) ($args['limit'] ?? 50);
        $offset = (int) ($args['offset'] ?? 0);
        $contacts = $query->offset($offset)->limit($limit)->get();

        return [
            'list' => ['id' => $list->id, 'name' => $list->name],
            'total' => $total,
            'offset' => $offset,
            'returned' => $contacts->count(),
            'contacts' => $contacts->map(fn (Contact $c): array => $this->presenter->contact($c))->values()->all(),
        ];
    }
}
