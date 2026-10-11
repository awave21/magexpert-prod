<?php

namespace App\Sender\Console;

use App\Sender\Services\CampaignService;
use Illuminate\Console\Command;

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

        return self::SUCCESS;
    }
}
