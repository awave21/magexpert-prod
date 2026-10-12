<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;

class GetCampaignTool implements Tool
{
    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'get_campaign';
    }

    public function title(): string
    {
        return 'Рассылка';
    }

    public function description(): string
    {
        return 'Рассылка целиком: настройки, статус, статистика с самыми кликабельными ссылками; у черновика — сколько подписчиков её получат '
            .'(deliverable_count), у идущей плавной рассылки — когда закончится. Gets a campaign with statistics.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['campaign' => Schemas::campaignRef()],
            'required' => ['campaign'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        return ['campaign' => $this->presenter->campaign($ctx->campaign((int) $args['campaign']), true)];
    }
}
