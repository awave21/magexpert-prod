<?php

namespace App\Sender\Jobs;

use App\Sender\Models\Campaign;
use App\Sender\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ставит в очередь письма рассылки всем подписчикам базы.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignService $campaigns): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null || $campaign->status !== Campaign::STATUS_SENDING) {
            return;
        }

        $campaigns->dispatchMessages($campaign);
    }
}
