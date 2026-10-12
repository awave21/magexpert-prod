<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\CampaignInput;
use App\Sender\Mcp\Tool;

class CountSegmentTool implements Tool
{
    public function name(): string
    {
        return 'count_segment';
    }

    public function title(): string
    {
        return 'Посчитать сегмент';
    }

    public function description(): string
    {
        return 'Сколько подписчиков базы получат рассылку с таким сегментом: подписаны, подтвердили подписку, адрес не забракован и не в блок-листе. '
            .'Без сегмента — вся база. Counts deliverable contacts of a list segment.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['list' => Schemas::listRef(), 'segment' => Schemas::segment()],
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
        $segment = CampaignInput::segment($args['segment'] ?? null);

        return [
            'list' => ['id' => $list->id, 'name' => $list->name],
            'segment' => $segment,
            'count' => $list->contacts()->deliverable()->inSegment($segment)->count(),
        ];
    }
}
