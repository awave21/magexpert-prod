<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Models\ContactList;
use stdClass;

class ListListsTool implements Tool
{
    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'list_lists';
    }

    public function title(): string
    {
        return 'Базы подписчиков';
    }

    public function description(): string
    {
        return 'Базы подписчиков организации: ID, название, сколько адресов всего, подписаны, ждут подтверждения подписки и получат рассылку (deliverable). '
            .'synced=true — база обновляется из источника автоматически, подписчиков в ней менять нельзя. Lists subscriber lists with counts.';
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
        $lists = $this->presenter->listsWithCounts($ctx->organization->lists())->orderBy('name')->get();

        return ['lists' => $lists->map(fn (ContactList $list): array => $this->presenter->list($list))->values()->all()];
    }
}
