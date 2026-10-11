<?php

namespace App\Sender\Console;

use App\Sender\Services\CampaignService;
use App\Sender\Services\HealthMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Раз в минуту: начинает рассылки по расписанию, ставит в очередь очередные пачки плавных рассылок
 * и отмечает завершённые.
 */
class CampaignsTickCommand extends Command
{
    protected $signature = 'sender:campaigns-tick';

    protected $description = 'Поставить в очередь очередные пачки запущенных рассылок';

    public function handle(CampaignService $campaigns): int
    {
        $campaigns->tickAll();
        Cache::forever(HealthMonitor::TICK_KEY, now());

        return self::SUCCESS;
    }
}
