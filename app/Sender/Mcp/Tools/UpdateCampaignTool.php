<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\CampaignInput;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;

class UpdateCampaignTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.campaigns.update';

    public function __construct(private readonly DataPresenter $presenter) {}

    public function name(): string
    {
        return 'update_campaign';
    }

    public function title(): string
    {
        return 'Изменить рассылку';
    }

    public function description(): string
    {
        return 'Меняет черновик рассылки: название, письмо, базу, сегмент, отслеживание, плавную отправку. Передавайте только то, что меняется; '
            .'null очищает поле. Запланированную или идущую рассылку изменить нельзя — сначала cancel_campaign. Updates a draft campaign.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign' => Schemas::campaignRef(),
                'name' => ['type' => 'string', 'maxLength' => 200],
                ...Schemas::campaignSettings(),
            ],
            'required' => ['campaign'],
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
        $campaign = $ctx->campaign((int) $args['campaign']);

        if (! $campaign->isDraft()) {
            throw new ToolException("Рассылка «{$campaign->name}» уже запланирована или отправлена: изменить можно только черновик");
        }

        $campaign->update(CampaignInput::attributes($ctx, $args, $campaign));
        $ctx->audit(self::AUDIT_ACTION, $campaign->name);

        return [
            'message' => "Черновик рассылки «{$campaign->name}» сохранён",
            'campaign' => $this->presenter->campaign($campaign->refresh()->load(['template', 'list']), true),
        ];
    }
}
