<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Models\Campaign;
use App\Sender\Services\CampaignService;

class ListCampaignsTool implements Tool
{
    public function __construct(
        private readonly DataPresenter $presenter,
        private readonly CampaignService $campaigns,
    ) {}

    public function name(): string
    {
        return 'list_campaigns';
    }

    public function title(): string
    {
        return 'Рассылки';
    }

    public function description(): string
    {
        return 'Рассылки организации, новые первыми: статус (draft, scheduled, sending, paused, cancelled, sent), письмо, база, сегмент, '
            .'время старта и статистика (доставлено, открыто, переходы, отказы, жалобы). Lists campaigns with statistics.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED, Campaign::STATUS_SENDING, Campaign::STATUS_PAUSED, Campaign::STATUS_CANCELLED, Campaign::STATUS_SENT]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $query = $ctx->organization->campaigns()->with(['template:id,name', 'list:id,name'])->latest('id');

        if (isset($args['status'])) {
            $query->where('status', $args['status']);
        }

        $total = (clone $query)->count();
        $campaigns = $query->limit((int) ($args['limit'] ?? 50))->get();
        $stats = $this->campaigns->statsForMany($campaigns->reject(fn (Campaign $c): bool => $c->isDraft()));

        return [
            'total' => $total,
            'returned' => $campaigns->count(),
            'campaigns' => $campaigns->map(fn (Campaign $c): array => $this->presenter->campaign($c, stats: $stats[$c->id] ?? null))->values()->all(),
        ];
    }
}
