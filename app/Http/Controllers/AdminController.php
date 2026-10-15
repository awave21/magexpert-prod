<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\MedicalLibrary;
use App\Models\Role;
use App\Models\Speaker;
use App\Models\User;
use App\Services\AdminAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /**
     * Отображает главную страницу админки.
     */
    public function index(Request $request, AdminAnalytics $analytics): Response
    {
        $period = AdminAnalytics::normalizePeriod($request->integer('period', AdminAnalytics::DEFAULT_PERIOD));

        // Статистика по ролям остаётся: на неё опираются другие экраны и тесты
        $stats = [
            'users_count' => User::count(),
            'roles_count' => Role::count(),
            'admin_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->count(),
            'editor_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'editor');
            })->count(),
            'manager_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'manager');
            })->count(),
        ];

        return Inertia::render('Admin/Index', [
            'stats' => $stats,
            'overview' => $this->overview(),
            'analytics' => $analytics->forPeriod($period),
            'upcoming' => $this->upcomingEvents(),
            'latestRegistrations' => $this->latestRegistrations(),
            'user' => auth()->user()->load('roles'),
        ]);
    }

    /**
     * Показатели за последние 30 дней в сравнении с предыдущими 30.
     *
     * @return array<string, int|float|array<string, int|float>>
     */
    private function overview(): array
    {
        $from = now()->subDays(30);
        $before = now()->subDays(60);

        $registrations = fn ($start, $end) => DB::table('event_user')
            ->where('access_granted_at', '>=', $start)
            ->when($end, fn ($query) => $query->where('access_granted_at', '<', $end))
            ->count();

        $paid = fn ($start, $end) => (float) DB::table('event_user')
            ->where('payment_status', 'completed')
            ->where('access_granted_at', '>=', $start)
            ->when($end, fn ($query) => $query->where('access_granted_at', '<', $end))
            ->sum('payment_amount');

        return [
            'registrations' => ['current' => $registrations($from, null), 'previous' => $registrations($before, $from)],
            'paid' => ['current' => $paid($from, null), 'previous' => $paid($before, $from)],
            'events_upcoming' => Event::query()->where('is_active', true)->where('is_archived', false)
                ->whereDate('start_date', '>=', now()->toDateString())->count(),
            'events_total' => Event::query()->count(),
            'speakers_total' => Speaker::query()->count(),
            'library_total' => MedicalLibrary::query()->count(),
        ];
    }

    /**
     * Ближайшие мероприятия с числом участников и готовностью карточки.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingEvents(): array
    {
        return Event::query()
            ->where('is_active', true)
            ->where('is_archived', false)
            ->whereDate('start_date', '>=', now()->toDateString())
            ->withCount(['users as registrations_count', 'speakers'])
            ->orderBy('start_date')
            ->limit(5)
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'start_date' => $event->start_date ? substr((string) $event->start_date, 0, 10) : null,
                'start_time' => $event->start_time,
                'registrations' => $event->registrations_count,
                'max_quantity' => $event->max_quantity,
                'speakers' => $event->speakers_count,
                'has_image' => ! empty($event->getRawOriginal('image')),
                'is_paid' => (bool) $event->is_paid,
            ])
            ->all();
    }

    /**
     * Последние выданные доступы.
     *
     * @return list<array<string, mixed>>
     */
    private function latestRegistrations(): array
    {
        return DB::table('event_user')
            ->join('users', 'users.id', '=', 'event_user.user_id')
            ->join('events', 'events.id', '=', 'event_user.event_id')
            ->orderByDesc('event_user.access_granted_at')
            ->limit(5)
            ->get([
                'users.id as user_id',
                'users.first_name',
                'users.last_name',
                'events.id as event_id',
                'events.title as event_title',
                'event_user.access_type',
                'event_user.payment_status',
                'event_user.access_granted_at',
            ])
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => trim($row->last_name.' '.$row->first_name),
                'event_id' => $row->event_id,
                'event_title' => $row->event_title,
                'access_type' => $row->access_type,
                'payment_status' => $row->payment_status,
                'granted_at' => $row->access_granted_at,
            ])
            ->all();
    }
}
