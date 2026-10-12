<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Services\CampaignService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CancelCampaignTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.campaigns.cancel';

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DataPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'cancel_campaign';
    }

    public function title(): string
    {
        return 'Отменить рассылку';
    }

    public function description(): string
    {
        return 'Отменяет запланированную, идущую или приостановленную рассылку: ни одно ещё не ушедшее письмо не отправится. '
            .'Ушедшие письма и статистика остаются. Отменённую рассылку снова запустить нельзя — создайте новую. Cancels a campaign.';
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
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireDataEditing();
        $campaign = $ctx->campaign((int) $args['campaign']);

        try {
            $this->campaigns->cancel($campaign);
        } catch (ValidationException $e) {
            throw new ToolException(implode('; ', Arr::flatten($e->errors())), $e->errors());
        }

        $ctx->audit(self::AUDIT_ACTION, $campaign->name);

        return [
            'message' => "Рассылка «{$campaign->name}» отменена",
            'campaign' => $this->presenter->campaign($campaign->refresh()->load(['template', 'list']), true),
        ];
    }
}
