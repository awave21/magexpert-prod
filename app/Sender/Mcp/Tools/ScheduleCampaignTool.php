<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\DataPresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Services\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Через ассистента рассылку можно только запланировать, не отправить сразу: до старта её можно отменить.
 */
class ScheduleCampaignTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.campaigns.schedule';

    public const TIMEZONE = 'Europe/Moscow';

    /**
     * Не раньше, чем через столько минут: остаётся время передумать и отменить.
     */
    public const MIN_LEAD_MINUTES = 5;

    public const MAX_DAYS_AHEAD = 365;

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DataPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'schedule_campaign';
    }

    public function title(): string
    {
        return 'Запланировать рассылку';
    }

    public function description(): string
    {
        return 'Планирует черновик рассылки на дату и время: в это время письма начнут уходить подписчикам базы (или сегмента). '
            .'Не раньше чем через '.self::MIN_LEAD_MINUTES.' минут и не позже чем через год. Время без часового пояса считается московским. '
            .'Отправить сразу через ассистента нельзя. До старта и во время отправки рассылку можно остановить через cancel_campaign. '
            .'Schedules a draft campaign; immediate sending is not available.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign' => Schemas::campaignRef(),
                'scheduled_at' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Дата и время старта, ISO 8601: "2026-11-15T10:00:00+03:00" или "2026-11-15 10:00" (по Москве).'],
            ],
            'required' => ['campaign', 'scheduled_at'],
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

        if (! $campaign->isDraft()) {
            throw new ToolException("Рассылка «{$campaign->name}» уже запланирована или отправлена");
        }

        $at = $this->parse((string) $args['scheduled_at']);

        if ($at->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw new ToolException('Запланировать можно не раньше чем через '.self::MIN_LEAD_MINUTES.' минут. Отправить сразу через ассистента нельзя.');
        }

        if ($at->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new ToolException('Запланировать можно не дальше чем на год вперёд');
        }

        // время сохранится вместе с запуском: если рассылка не готова, черновик останется без изменений
        $campaign->scheduled_at = $at;

        try {
            $this->campaigns->start($campaign);
        } catch (ValidationException $e) {
            throw new ToolException('Рассылку нельзя запланировать: '.implode('; ', Arr::flatten($e->errors())), $e->errors());
        }

        $ctx->audit(self::AUDIT_ACTION, $campaign->name);
        $local = $at->setTimezone(self::TIMEZONE)->format('d.m.Y H:i');

        return [
            'message' => "Рассылка «{$campaign->name}» запланирована на {$local} по Москве: {$campaign->recipients_count} получателей",
            'campaign' => $this->presenter->campaign($campaign->refresh()->load(['template', 'list']), true),
        ];
    }

    private function parse(string $value): CarbonImmutable
    {
        try {
            // в базу время пишется без пояса: приводим к поясу приложения, иначе «+00:00» сдвинет старт
            return CarbonImmutable::parse(trim($value), self::TIMEZONE)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new ToolException("Не понял дату «{$value}»: укажите её как 2026-11-15T10:00:00+03:00 или 2026-11-15 10:00");
        }
    }
}
