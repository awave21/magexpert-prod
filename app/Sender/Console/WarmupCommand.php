<?php

namespace App\Sender\Console;

use App\Sender\Services\Warmup;
use Illuminate\Console\Command;

/**
 * Где мы в графике прогрева IP.
 */
class WarmupCommand extends Command
{
    protected $signature = 'sender:warmup';

    protected $description = 'Показать день прогрева IP и лимит писем рассылок на сегодня';

    public function handle(Warmup $warmup): int
    {
        $status = $warmup->status();

        if ($status === null) {
            $this->info(blank(config('sender.warmup.start'))
                ? 'Прогрев не включён. Чтобы начать: SENDER_WARMUP_START=ГГГГ-ММ-ДД в .env'
                : 'Прогрев не идёт: дата старта ещё не наступила или график закончился, лимита нет');

            return self::SUCCESS;
        }

        $this->info("День {$status['day']} из {$status['days']}: сегодня можно {$status['limit']} писем рассылок, отправлено {$status['sent']}, осталось {$status['remaining']}");
        $this->line('График: '.implode(', ', (array) config('sender.warmup.schedule')));

        return self::SUCCESS;
    }
}
