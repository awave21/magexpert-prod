<?php

namespace App\Sender\Services;

use App\Sender\Models\Campaign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Окно отправки и оценка времени окончания плавной рассылки. Время окна задаётся в часовом поясе sender.timezone.
 */
class CampaignSchedule
{
    /**
     * Больше шагов оценка не считает: столько пачек не бывает у разумной рассылки.
     */
    private const MAX_STEPS = 20000;

    public function inWindow(Campaign $campaign, CarbonInterface $moment): bool
    {
        $local = $this->local($moment);

        if (! $this->dayAllowed($campaign, $local)) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $this->start($campaign) && ($campaign->window_end === null || $time < $campaign->window_end);
    }

    /**
     * Ближайший момент не раньше $moment, когда можно отправлять. Без окна — сам $moment.
     */
    public function nextOpening(Campaign $campaign, CarbonInterface $moment): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($moment);

        if ($this->inWindow($campaign, $moment)) {
            return $moment;
        }

        $local = $this->local($moment);
        [$hour, $minute] = array_map('intval', explode(':', $this->start($campaign)));

        for ($day = 0; $day <= 7; $day++) {
            $candidate = $local->startOfDay()->addDays($day)->setTime($hour, $minute);

            if ($candidate->greaterThan($local) && $this->dayAllowed($campaign, $candidate)) {
                return $candidate->setTimezone($moment->getTimezone());
            }
        }

        return $moment;
    }

    /**
     * Когда уйдёт последняя пачка плавной рассылки. Общий часовой лимит сервера не учитывается.
     *
     * @param  int  $remaining  сколько писем ещё не поставлено в очередь
     * @param  int  $dispatchedToday  сколько писем рассылки уже ушло сегодня (по часовому поясу окна)
     */
    public function estimateFinish(Campaign $campaign, int $remaining, int $dispatchedToday, CarbonInterface $now): ?CarbonImmutable
    {
        if (! $campaign->isDrip() || $remaining <= 0 || ($campaign->batch_size ?? 0) < 1) {
            return null;
        }

        $moment = CarbonImmutable::instance($now);
        if ($campaign->next_batch_at !== null && $campaign->next_batch_at->greaterThan($moment)) {
            $moment = $campaign->next_batch_at;
        }

        $interval = max(1, (int) $campaign->batch_interval);
        $day = $this->local($moment)->toDateString();
        $sentOnDay = $dispatchedToday;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $moment = $this->nextOpening($campaign, $moment);

            if ($this->local($moment)->toDateString() !== $day) {
                $day = $this->local($moment)->toDateString();
                $sentOnDay = 0;
            }

            $batch = $campaign->batch_size;
            if ($campaign->daily_limit) {
                $batch = min($batch, $campaign->daily_limit - $sentOnDay);
            }

            if ($batch <= 0) {
                $moment = $this->local($moment)->startOfDay()->addDay()->setTimezone($moment->getTimezone());

                continue;
            }

            $remaining -= $batch;
            $sentOnDay += $batch;

            if ($remaining <= 0) {
                return $moment;
            }

            $moment = $moment->addMinutes($interval);
        }

        return null;
    }

    public function startOfLocalDay(CarbonInterface $moment): CarbonImmutable
    {
        return $this->local($moment)->startOfDay()->setTimezone($moment->getTimezone());
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('sender.timezone', 'Europe/Moscow'));
    }

    private function start(Campaign $campaign): string
    {
        return $campaign->window_start ?? '00:00';
    }

    private function dayAllowed(Campaign $campaign, CarbonInterface $local): bool
    {
        $days = $campaign->window_days;

        return empty($days) || in_array($local->isoWeekday(), array_map('intval', $days), true);
    }
}
