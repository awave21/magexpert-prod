<?php

namespace App\Sender\Console;

use App\Sender\Services\HealthMonitor;
use App\Sender\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Проверяет Sender и пишет в Telegram о новых проблемах, напоминает о нерешённых раз в 6 часов
 * и сообщает, когда проблема ушла.
 */
class HealthCommand extends Command
{
    private const STATE_KEY = 'sender.health.alerts';

    private const REMIND_HOURS = 6;

    protected $signature = 'sender:health {--print : только показать проблемы, не отправлять в Telegram}';

    protected $description = 'Проверить Sender (очереди, Postfix, отказы, чёрные списки) и сообщить о проблемах в Telegram';

    public function handle(HealthMonitor $monitor, TelegramNotifier $telegram): int
    {
        $problems = $monitor->problems();

        if ($this->option('print')) {
            $problems === []
                ? $this->info('Проблем нет')
                : $this->table(['Проверка', 'Что случилось'], collect($problems)->map(fn (string $text, string $key): array => [$key, $text])->values()->all());

            return self::SUCCESS;
        }

        /** @var array<string, array{text: string, notified_at: int}> $state */
        $state = Cache::get(self::STATE_KEY, []);
        $now = now()->getTimestamp();

        foreach ($problems as $key => $text) {
            $known = $state[$key] ?? null;

            if ($known === null || $now - $known['notified_at'] >= self::REMIND_HOURS * 3600) {
                $prefix = $known === null ? '⚠️ Sender' : '⚠️ Sender, всё ещё';
                $sent = $telegram->send("{$prefix}: {$text}");
                $state[$key] = ['text' => $text, 'notified_at' => $sent ? $now : ($known['notified_at'] ?? 0)];
            }
        }

        foreach (array_diff_key($state, $problems) as $key => $known) {
            $telegram->send('✅ Sender, исправлено: '.$known['text']);
            unset($state[$key]);
        }

        Cache::forever(self::STATE_KEY, $state);

        return self::SUCCESS;
    }
}
