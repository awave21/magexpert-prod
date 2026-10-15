<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Показатели для главной страницы админки: регистрации, активность, записи, ИИ-помощник.
 *
 * Отдельного журнала посещений сайта в приложении нет, поэтому «активность» считаем по
 * событиям, которые пишутся сами: просмотр записи, вопрос помощнику, открытие документа,
 * регистрация на мероприятие.
 */
class AdminAnalytics
{
    public const array PERIODS = [7, 30, 90, 365];

    public const int DEFAULT_PERIOD = 30;

    /** Границы глубины просмотра для распределения: подпись и верхняя граница доли. */
    private const array DEPTH_BUCKETS = [
        ['label' => 'до 25%', 'max' => 0.25],
        ['label' => '25–50%', 'max' => 0.5],
        ['label' => '50–75%', 'max' => 0.75],
        ['label' => '75–95%', 'max' => 0.95],
        ['label' => 'досмотрели', 'max' => INF],
    ];

    public static function normalizePeriod(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(int $days): array
    {
        $days = self::normalizePeriod($days);

        return Cache::remember("admin.analytics.{$days}", now()->addMinutes(5), fn (): array => $this->build($days));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $previousSince = $since->copy()->subDays($days);
        $labels = $this->dayLabels($since, $days);

        return [
            'period' => $days,
            'generated_at' => now()->format('H:i'),
            'kpis' => $this->kpis($since, $previousSince),
            'activity' => $this->activity($since, $labels),
            'registrations' => $this->registrations($since, $labels),
            'video' => $this->video($since, $labels),
            'assistant' => $this->assistant($since, $labels),
            'heatmap' => $this->heatmap($since),
            'online' => $this->online(),
        ];
    }

    /**
     * @return list<string> даты периода в формате Y-m-d
     */
    private function dayLabels(Carbon $since, int $days): array
    {
        return collect(range(0, $days - 1))
            ->map(fn (int $offset): string => $since->copy()->addDays($offset)->toDateString())
            ->all();
    }

    /**
     * @param  list<string>  $labels
     * @param  \Illuminate\Support\Collection<string, int|float>  $rows
     * @return list<int|float>
     */
    private function fill(array $labels, $rows): array
    {
        return array_map(fn (string $day) => $rows[$day] ?? 0, $labels);
    }

    /**
     * Подзапрос «кто был активен»: пары (день, пользователь) из всех источников активности.
     */
    private function activeUsersSql(): string
    {
        return '
            select date(updated_at) as d, user_id from video_progress where updated_at >= ? and updated_at < ?
            union all
            select date(created_at), user_id from assistant_questions where user_id is not null and created_at >= ? and created_at < ?
            union all
            select date(created_at), user_id from library_document_opens where user_id is not null and created_at >= ? and created_at < ?
            union all
            select date(access_granted_at), user_id from event_user where access_granted_at >= ? and access_granted_at < ?
        ';
    }

    /**
     * @return list<string>
     */
    private function bounds(Carbon $from, Carbon $to): array
    {
        $values = [];
        for ($i = 0; $i < 4; $i++) {
            $values[] = $from->toDateTimeString();
            $values[] = $to->toDateTimeString();
        }

        return $values;
    }

    private function activeUsers(Carbon $from, Carbon $to): int
    {
        return (int) DB::selectOne(
            'select count(distinct user_id) as c from ('.$this->activeUsersSql().') a',
            $this->bounds($from, $to),
        )->c;
    }

    /**
     * @return array<string, array{current: int|float, previous: int|float}>
     */
    private function kpis(Carbon $since, Carbon $previousSince): array
    {
        $now = now()->addDay();

        $between = fn (string $table, string $column, Carbon $from, Carbon $to) => DB::table($table)
            ->where($column, '>=', $from)
            ->where($column, '<', $to);

        $pair = fn (callable $metric): array => [
            'current' => $metric($since, $now),
            'previous' => $metric($previousSince, $since),
        ];

        return [
            'registrations' => $pair(fn ($from, $to) => $between('event_user', 'access_granted_at', $from, $to)->count()),
            'paid' => $pair(fn ($from, $to) => (float) $between('event_user', 'access_granted_at', $from, $to)
                ->where('payment_status', 'completed')->sum('payment_amount')),
            'new_users' => $pair(fn ($from, $to) => $between('users', 'created_at', $from, $to)->count()),
            'active_users' => $pair(fn ($from, $to) => $this->activeUsers($from, $to)),
            'video_viewers' => $pair(fn ($from, $to) => (int) $between('video_progress', 'updated_at', $from, $to)
                ->distinct()->count('user_id')),
            'questions' => $pair(fn ($from, $to) => $between('assistant_questions', 'created_at', $from, $to)->count()),
        ];
    }

    /**
     * Ряды по дням для общего графика активности.
     *
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function activity(Carbon $since, array $labels): array
    {
        $perDay = fn (string $table, string $column, string $expression = 'count(*)') => $this->fill(
            $labels,
            DB::table($table)
                ->where($column, '>=', $since)
                ->selectRaw("date({$column}) as d, {$expression} as c")
                ->groupBy('d')
                ->pluck('c', 'd')
                ->map(fn ($value) => (int) $value),
        );

        $active = DB::select(
            'select d, count(distinct user_id) as c from ('.$this->activeUsersSql().') a group by d',
            $this->bounds($since, now()->addDay()),
        );

        return [
            'labels' => $labels,
            'registrations' => $perDay('event_user', 'access_granted_at'),
            'new_users' => $perDay('users', 'created_at'),
            'video_viewers' => $perDay('video_progress', 'updated_at', 'count(distinct user_id)'),
            'questions' => $perDay('assistant_questions', 'created_at'),
            'opens' => $perDay('library_document_opens', 'created_at'),
            'active_users' => $this->fill($labels, collect($active)->mapWithKeys(fn ($row) => [(string) $row->d => (int) $row->c])),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function registrations(Carbon $since, array $labels): array
    {
        $base = fn () => DB::table('event_user')->where('access_granted_at', '>=', $since);

        $byType = $base()->selectRaw('access_type, count(*) as c')->groupBy('access_type')->pluck('c', 'access_type');
        $byStatus = $base()->selectRaw('payment_status, count(*) as c')->groupBy('payment_status')->pluck('c', 'payment_status');

        $top = $base()
            ->join('events', 'events.id', '=', 'event_user.event_id')
            ->selectRaw("events.id, events.title, count(*) as registrations, count(*) filter (where event_user.payment_status = 'completed') as paid, coalesce(sum(event_user.payment_amount) filter (where event_user.payment_status = 'completed'), 0) as revenue")
            ->groupBy('events.id', 'events.title')
            ->orderByDesc('registrations')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'registrations' => (int) $row->registrations,
                'paid' => (int) $row->paid,
                'revenue' => (float) $row->revenue,
            ])
            ->all();

        $typeLabels = ['free' => 'Бесплатные', 'paid' => 'Платные', 'promotional' => 'Промо', 'admin' => 'Выданы вручную', 'invited' => 'По приглашению'];
        $statusLabels = ['completed' => 'Оплачено', 'free' => 'Бесплатно', 'pending' => 'Ждёт оплаты', 'failed' => 'Ошибка оплаты', 'refunded' => 'Возврат'];

        return [
            'total' => (int) $byType->sum(),
            'by_type' => $byType->map(fn ($count, $type): array => ['key' => $type, 'label' => $typeLabels[$type] ?? $type, 'count' => (int) $count])->values()->all(),
            'by_status' => $byStatus->map(fn ($count, $status): array => ['key' => $status, 'label' => $statusLabels[$status] ?? $status, 'count' => (int) $count])->values()->all(),
            'top_events' => $top,
        ];
    }

    /**
     * Просмотр записей и глубина: берём последнее состояние плеера по каждому зрителю и ролику.
     *
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function video(Carbon $since, array $labels): array
    {
        $depth = 'least(position::float / nullif(duration, 0), 1)';

        $summary = DB::table('video_progress')
            ->where('updated_at', '>=', $since)
            ->where('duration', '>', 0)
            ->selectRaw("count(distinct user_id) as viewers, count(*) as views, coalesce(avg({$depth}), 0) as avg_depth, count(*) filter (where completed_at is not null) as completed, coalesce(sum(position), 0) as seconds")
            ->first();

        $depths = DB::table('video_progress')
            ->where('updated_at', '>=', $since)
            ->where('duration', '>', 0)
            ->selectRaw("{$depth} as depth")
            ->pluck('depth')
            ->map(fn ($value): float => (float) $value);

        $buckets = collect(self::DEPTH_BUCKETS)->map(function (array $bucket, int $index) use ($depths): array {
            $min = $index === 0 ? -1 : self::DEPTH_BUCKETS[$index - 1]['max'];

            return [
                'label' => $bucket['label'],
                'count' => $depths->filter(fn (float $value): bool => $value > $min && $value <= $bucket['max'])->count(),
            ];
        })->all();

        // Сколько зрителей дошло хотя бы до 10, 20 … 100% ролика
        $retention = collect(range(0, 10))->map(fn (int $step): array => [
            'label' => ($step * 10).'%',
            'share' => $depths->isEmpty() ? 0 : round($depths->filter(fn (float $value): bool => $value >= $step / 10 - 0.0001)->count() / $depths->count() * 100, 1),
        ])->all();

        $top = DB::table('video_progress')
            ->join('events', 'events.id', '=', 'video_progress.event_id')
            ->where('video_progress.updated_at', '>=', $since)
            ->where('video_progress.duration', '>', 0)
            ->selectRaw('events.id, events.title, video_progress.video_key, count(distinct video_progress.user_id) as viewers, avg(least(video_progress.position::float / nullif(video_progress.duration, 0), 1)) as avg_depth, count(*) filter (where video_progress.completed_at is not null) as completed, count(*) as views, sum(video_progress.position) as seconds, max(video_progress.duration) as duration')
            ->groupBy('events.id', 'events.title', 'video_progress.video_key')
            ->orderByDesc('viewers')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'event_id' => (int) $row->id,
                'title' => $row->title,
                'video_key' => $row->video_key,
                'viewers' => (int) $row->viewers,
                'avg_depth' => round((float) $row->avg_depth * 100, 1),
                'completed_rate' => $row->views > 0 ? round($row->completed / $row->views * 100, 1) : 0.0,
                'minutes' => (int) round($row->seconds / 60),
                'duration_minutes' => (int) round($row->duration / 60),
            ])
            ->all();

        return [
            'summary' => [
                'viewers' => (int) $summary->viewers,
                'views' => (int) $summary->views,
                'avg_depth' => round((float) $summary->avg_depth * 100, 1),
                'completed_rate' => $summary->views > 0 ? round($summary->completed / $summary->views * 100, 1) : 0.0,
                'hours' => round($summary->seconds / 3600, 1),
            ],
            'buckets' => $buckets,
            'retention' => $retention,
            'top' => $top,
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function assistant(Carbon $since, array $labels): array
    {
        $totals = DB::table('assistant_questions')
            ->where('created_at', '>=', $since)
            ->selectRaw('count(*) as questions, count(*) filter (where found) as found, count(distinct user_id) as users, coalesce(avg(duration_ms), 0) as duration')
            ->first();

        $perDay = DB::table('assistant_questions')
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as d, count(*) as total, count(*) filter (where found) as found')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => (string) $row->d);

        $missing = DB::table('assistant_questions')
            ->where('created_at', '>=', $since)
            ->where('found', false)
            ->selectRaw('min(question) as question, count(*) as times')
            ->groupByRaw('lower(trim(question))')
            ->orderByDesc('times')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => ['question' => $row->question, 'times' => (int) $row->times])
            ->all();

        $documents = DB::table('library_document_opens')
            ->join('medical_libraries', 'medical_libraries.id', '=', 'library_document_opens.medical_library_id')
            ->where('library_document_opens.created_at', '>=', $since)
            ->selectRaw("medical_libraries.id, medical_libraries.title, count(*) as opens, count(*) filter (where library_document_opens.source = 'assistant') as from_assistant")
            ->groupBy('medical_libraries.id', 'medical_libraries.title')
            ->orderByDesc('opens')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'opens' => (int) $row->opens,
                'from_assistant' => (int) $row->from_assistant,
            ])
            ->all();

        $opens = DB::table('library_document_opens')
            ->where('created_at', '>=', $since)
            ->selectRaw('source, count(*) as c')
            ->groupBy('source')
            ->pluck('c', 'source');

        return [
            'summary' => [
                'questions' => (int) $totals->questions,
                'found' => (int) $totals->found,
                'found_rate' => $totals->questions > 0 ? round($totals->found / $totals->questions * 100, 1) : 0.0,
                'users' => (int) $totals->users,
                'seconds' => round($totals->duration / 1000, 1),
                'opens_assistant' => (int) ($opens['assistant'] ?? 0),
                'opens_library' => (int) ($opens['library'] ?? 0),
            ],
            'by_day' => [
                'total' => array_map(fn (string $day): int => (int) ($perDay[$day]->total ?? 0), $labels),
                'found' => array_map(fn (string $day): int => (int) ($perDay[$day]->found ?? 0), $labels),
            ],
            'missing' => $missing,
            'documents' => $documents,
        ];
    }

    /**
     * Когда пользователи активны: день недели (пн = 0) и час.
     *
     * @return list<list<int>>
     */
    private function heatmap(Carbon $since): array
    {
        $sql = '
            select (extract(isodow from t) - 1)::int as dow, extract(hour from t)::int as hour, count(*) as c
            from (
                select updated_at as t from video_progress where updated_at >= ?
                union all select created_at from assistant_questions where created_at >= ?
                union all select created_at from library_document_opens where created_at >= ?
                union all select access_granted_at from event_user where access_granted_at >= ?
            ) events
            group by 1, 2
        ';

        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach (DB::select($sql, array_fill(0, 4, $since->toDateTimeString())) as $row) {
            $grid[(int) $row->dow][(int) $row->hour] = (int) $row->c;
        }

        return $grid;
    }

    /**
     * Сейчас на сайте: авторизованные с активностью за последние 5 минут.
     */
    private function online(): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        return (int) DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
            ->distinct()
            ->count('user_id');
    }
}
