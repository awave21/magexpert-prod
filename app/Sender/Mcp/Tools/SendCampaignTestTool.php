<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Message;
use App\Sender\Services\CampaignService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class SendCampaignTestTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.campaigns.test';

    public function __construct(private readonly CampaignService $campaigns) {}

    public function name(): string
    {
        return 'send_campaign_test';
    }

    public function title(): string
    {
        return 'Тест рассылки себе';
    }

    public function description(): string
    {
        return 'Отправляет тест рассылки на почту владельца ключа (только себе) так, как его увидит подписчик: с его переменными и ссылкой отписки, '
            .'тема с пометкой «Тест». contact_email — чьи данные подставить (адрес из базы рассылки), иначе первый подходящий подписчик. '
            .'В статистику не попадает. Вместе с send_test — не больше '.SendTestTool::PER_HOUR.' писем в час. '
            .'Sends a campaign test to the key owner\'s own email.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign' => Schemas::campaignRef(),
                'contact_email' => ['type' => 'string', 'maxLength' => 190, 'description' => 'Адрес подписчика из базы рассылки, чьи данные подставить.'],
            ],
            'required' => ['campaign'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireDataEditing();
        $campaign = $ctx->campaign((int) $args['campaign']);
        $ctx->throttleTestLetters(SendTestTool::PER_HOUR);

        try {
            $message = $this->campaigns->sendTest($campaign, $ctx->user->email, filled($args['contact_email'] ?? null) ? (string) $args['contact_email'] : null);
        } catch (ValidationException $e) {
            throw new ToolException(implode('; ', Arr::flatten($e->errors())), $e->errors());
        }

        if ($message->status === Message::STATUS_BLOCKED) {
            throw new ToolException('Тестовое письмо не отправлено: '.$message->error);
        }

        $ctx->audit(self::AUDIT_ACTION, $campaign->name);

        return [
            'message' => "Тест рассылки «{$campaign->name}» поставлен в очередь на {$ctx->user->email}",
            'to' => $ctx->user->email,
            'message_uuid' => $message->uuid,
            'status' => $message->status,
        ];
    }
}
