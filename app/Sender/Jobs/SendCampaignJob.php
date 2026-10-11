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
 * Готовит письма рассылки подписчикам базы: по CampaignService::PREPARE_PER_JOB за раз, затем ставит своё продолжение.
 * Короткие задачи укладываются в retry_after очереди, поэтому не запускаются повторно параллельно.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public int $campaignId, public int $afterContactId = 0) {}

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

        $next = $campaigns->prepare($campaign, $this->afterContactId);

        if ($next !== null) {
            self::dispatch($this->campaignId, $next)->onQueue(config('sender.queue'));
        }
    }
}
