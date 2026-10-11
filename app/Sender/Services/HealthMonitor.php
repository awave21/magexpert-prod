<?php

namespace App\Sender\Services;

use App\Sender\Dns\Blacklists;
use App\Sender\Models\Campaign;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Проверки, после которых нужно вмешаться: письма не уходят, Postfix копит очередь, растут отказы, IP в чёрном списке.
 */
class HealthMonitor
{
    /**
     * Когда sender:campaigns-tick последний раз отработал.
     */
    public const TICK_KEY = 'sender.campaigns_tick_at';

    public function __construct(private readonly Blacklists $blacklists) {}

    /**
     * @return array<string, string> ключ проблемы => что случилось и что проверить
     */
    public function problems(): array
    {
        return array_filter([
            'transactional_queue' => $this->stuckTransactional(),
            'campaign_queue' => $this->stuckCampaigns(),
            'sending' => $this->stuckSending(),
            'scheduler' => $this->schedulerStopped(),
            'postfix_queue' => $this->postfixQueue(),
            'bounces' => $this->bounceRate(),
            'failed' => $this->failed(),
            'complaints' => $this->complaints(),
            'blacklist' => $this->blacklisted(),
        ]);
    }

    private function stuckTransactional(): ?string
    {
        $stuck = Message::query()->whereNull('campaign_id')->where('status', Message::STATUS_QUEUED)->where('created_at', '<', now()->subMinutes(5));
        $count = (clone $stuck)->count();

        if ($count === 0) {
            return null;
        }

        $minutes = (int) Carbon::parse((clone $stuck)->min('created_at'))->diffInMinutes(now());

        return "Служебные письма не уходят: {$count} в очереди, самое старое ждёт {$minutes} мин. Проверьте процесс служебных писем: supervisorctl status sender-transactional.";
    }

    private function stuckCampaigns(): ?string
    {
        $count = Message::query()->whereNotNull('campaign_id')->where('status', Message::STATUS_QUEUED)->where('dispatched_at', '<', now()->subMinutes(30))->count();

        return $count > 0 ? "Письма рассылок больше 30 минут стоят в очереди: {$count}. Проверьте процесс рассылок: supervisorctl status sender-campaigns." : null;
    }

    private function stuckSending(): ?string
    {
        $count = Message::query()->where('status', Message::STATUS_SENDING)->where('updated_at', '<', now()->subMinutes(15))->count();

        return $count > 0 ? "{$count} писем больше 15 минут в статусе «отправляется»: воркер, вероятно, упал во время отправки. Сверьте их с /var/log/mail.log." : null;
    }

    private function schedulerStopped(): ?string
    {
        if (! Campaign::query()->whereIn('status', Campaign::ACTIVE_STATUSES)->whereNotNull('prepared_at')->exists()) {
            return null;
        }

        $last = Cache::get(self::TICK_KEY);

        return $last === null || Carbon::parse($last)->diffInMinutes(now()) > 5
            ? 'Рассылки запущены, но sender:campaigns-tick не выполнялся больше 5 минут: проверьте cron с php artisan schedule:run.'
            : null;
    }

    private function postfixQueue(): ?string
    {
        $binary = (string) config('sender.alerts.postqueue');

        if (! is_executable($binary)) {
            return null;
        }

        $result = Process::timeout(20)->run([$binary, '-j']);

        if (! $result->successful()) {
            return null;
        }

        $count = count(array_filter(explode("\n", trim($result->output()))));
        $max = (int) config('sender.alerts.postfix_queue');

        return $count > $max
            ? "В очереди Postfix {$count} писем (норма до {$max}): почтовые сервисы откладывают приём. Посмотрите причины: mailq | tail -50."
            : null;
    }

    private function bounceRate(): ?string
    {
        $events = MessageEvent::query()
            ->whereIn('type', [MessageEvent::TYPE_DELIVERED, MessageEvent::TYPE_BOUNCED])
            ->where('created_at', '>=', now()->subHour())
            ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        $bounced = (int) ($events[MessageEvent::TYPE_BOUNCED] ?? 0);
        $total = $bounced + (int) ($events[MessageEvent::TYPE_DELIVERED] ?? 0);
        $limit = (float) config('sender.alerts.bounce_rate');

        if ($total < 50 || $bounced / $total * 100 <= $limit) {
            return null;
        }

        $rate = round($bounced / $total * 100, 1);

        return "За час {$rate}% отказов ({$bounced} из {$total}), норма до {$limit}%. Если идёт рассылка, поставьте её на паузу и посмотрите ответы серверов в журнале.";
    }

    private function failed(): ?string
    {
        $count = Message::query()->where('status', Message::STATUS_FAILED)->where('updated_at', '>=', now()->subHour())->count();

        return $count >= 20 ? "За час не удалось отправить {$count} писем. Причина в журнале отправки Sender, поле «Ошибка»." : null;
    }

    /**
     * Почтовые сервисы начинают резать доставку уже при 0,1–0,3% жалоб.
     */
    private function complaints(): ?string
    {
        $since = now()->subDay();
        $complaints = MessageEvent::query()->where('type', MessageEvent::TYPE_COMPLAINT)->where('created_at', '>=', $since)->count();
        $delivered = MessageEvent::query()->where('type', MessageEvent::TYPE_DELIVERED)->where('created_at', '>=', $since)->count();

        if ($complaints < 3 || $complaints / max(1, $delivered) * 100 <= 0.1) {
            return null;
        }

        $rate = round($complaints / max(1, $delivered) * 100, 2);

        return "За сутки {$complaints} жалоб «Это спам» ({$rate}% от доставленных, норма до 0,1%). Пожаловавшиеся уже отписаны; проверьте, кому и что отправляли.";
    }

    private function blacklisted(): ?string
    {
        $ip = (string) config('sender.server_ip');

        if ($ip === '') {
            return null;
        }

        $lists = $this->blacklists->listedIn($ip, (array) config('sender.alerts.blacklists'));

        return $lists !== []
            ? "IP {$ip} в чёрных списках: ".implode(', ', $lists).'. Письма будут попадать в спам. Проверка и снятие: mxtoolbox.com/blacklists.'
            : null;
    }
}
