<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\CampaignInput;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Models\Campaign;

class CreateCampaignTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.campaigns.store';

    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'create_campaign';
    }

    public function title(): string
    {
        return 'Создать рассылку';
    }

    public function description(): string
    {
        return 'Создаёт черновик рассылки: письмо (template), база (list), при необходимости сегмент и плавная отправка (send_mode=drip с пачками и окном). '
            .'Черновик ничего не отправляет. Проверить — send_campaign_test, запланировать отправку — schedule_campaign. '
            .'Creates a draft campaign.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Название рассылки (видно только команде).'],
                ...Schemas::campaignSettings(),
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

        $campaign = $ctx->organization->campaigns()->create([...CampaignInput::attributes($ctx, $args), 'status' => Campaign::STATUS_DRAFT]);
        $ctx->audit(self::AUDIT_ACTION, $campaign->name);

        return [
            'message' => "Черновик рассылки «{$campaign->name}» создан",
            'campaign' => $this->presenter->campaign($campaign->load(['template', 'list']), true),
        ];
    }
}
