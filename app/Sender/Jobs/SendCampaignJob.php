<?php

namespace App\Sender\Jobs;

use App\Sender\Models\Campaign;
use App\Sender\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Готовит рассылку к отправке в два шага: сначала проверяет ещё не проверенные адреса, потом готовит письма
 * подписчикам базы по CampaignService::PREPARE_PER_JOB за раз. Каждый шаг ставит своё продолжение.
 * Короткие задачи укладываются в retry_after очереди, поэтому не запускаются повторно параллельно.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Проверка адресов короче этого — письма готовятся в той же задаче.
     */
    public const QUICK_CHECK_SECONDS = 10;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public int $campaignId, public int $afterContactId = 0, public bool $checking = true) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sender-campaign-'.$this->campaignId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null || ! in_array($campaign->status, [...Campaign::ACTIVE_STATUSES, Campaign::STATUS_PAUSED], true)) {
            return;
        }

        if ($this->checking) {
            $started = microtime(true);
            $next = $campaigns->checkAddresses($campaign, $this->afterContactId);

            // после короткой проверки письма готовятся в этой же задаче, после долгой — в следующей, чтобы уложиться в timeout
            if ($next !== null || microtime(true) - $started > self::QUICK_CHECK_SECONDS) {
                self::dispatch($this->campaignId, $next ?? 0, $next !== null)->onQueue(config('sender.queue'));

                return;
            }
        }

        $next = $campaigns->prepare($campaign, $this->checking ? 0 : $this->afterContactId);

        if ($next !== null) {
            self::dispatch($this->campaignId, $next, false)->onQueue(config('sender.queue'));
        }
    }
}
