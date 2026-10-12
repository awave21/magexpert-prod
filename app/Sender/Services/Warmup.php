<?php

namespace App\Sender\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Прогрев нового IP: письма рассылок и письма подтверждения подписки всех организаций ограничены по дням
 * графиком из sender.warmup. Остальные служебные письма в лимит не входят. День считается по часовому поясу окна отправки.
 */
class Warmup
{
    public function __construct(private readonly CampaignSchedule $schedule) {}

    /**
     * Номер дня прогрева, начиная с 1. null — прогрев не начат или закончился.
     */
    public function day(?CarbonInterface $now = null): ?int
    {
        $start = config('sender.warmup.start');
        $plan = (array) config('sender.warmup.schedule');

        if (blank($start) || $plan === []) {
            return null;
        }

        $timezone = (string) config('sender.timezone', 'Europe/Moscow');
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($timezone)->startOfDay();
        $day = (int) CarbonImmutable::parse((string) $start, $timezone)->startOfDay()->diffInDays($today) + 1;

        return $day >= 1 && $day <= count($plan) ? $day : null;
    }

    /**
     * Сколько писем рассылок можно отправить сегодня. null — без ограничения.
     */
    public function limitToday(?CarbonInterface $now = null): ?int
    {
        $day = $this->day($now);

        return $day === null ? null : (int) config('sender.warmup.schedule')[$day - 1];
    }

    public function sentToday(?CarbonInterface $now = null): int
    {
        return MessageService::bulkMessages()
            ->where('dispatched_at', '>=', $this->schedule->startOfLocalDay($now ?? now()))
            ->count();
    }

    public function remainingToday(?CarbonInterface $now = null): ?int
    {
        $limit = $this->limitToday($now);

        return $limit === null ? null : max(0, $limit - $this->sentToday($now));
    }

    /**
     * @return array{day: int, days: int, limit: int, sent: int, remaining: int}|null
     */
    public function status(?CarbonInterface $now = null): ?array
    {
        $day = $this->day($now);

        if ($day === null) {
            return null;
        }

        $limit = (int) $this->limitToday($now);
        $sent = $this->sentToday($now);

        return ['day' => $day, 'days' => count((array) config('sender.warmup.schedule')), 'limit' => $limit, 'sent' => $sent, 'remaining' => max(0, $limit - $sent)];
    }
}
