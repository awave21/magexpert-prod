<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Отображает дашборд пользователя.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Получаем live мероприятия, к которым у пользователя есть доступ
        $liveEvents = $user->liveEvents->take(3);

        // Получаем предстоящие мероприятия, к которым у пользователя есть доступ
        $upcomingEvents = $user->upcomingEvents
            ->reject(function($event) use ($liveEvents) {
                return $liveEvents->contains('id', $event->id);
            })
            ->take(5);

        // Добавляем информацию о доступе к каждому мероприятию
        $liveEventsWithAccess = $liveEvents->map(function($event) use ($user) {
            $event->access_type = $user->getEventAccessType($event);
            $event->payment_status = $user->getEventPaymentStatus($event);
            return $event;
        });

        $upcomingEventsWithAccess = $upcomingEvents->map(function($event) use ($user) {
            $event->access_type = $user->getEventAccessType($event);
            $event->payment_status = $user->getEventPaymentStatus($event);
            return $event;
        });

        return Inertia::render('Dashboard', [
            'liveEvents' => $liveEventsWithAccess,
            'upcomingEvents' => $upcomingEventsWithAccess,
        ]);
    }

    /**
     * Отображает все доступные мероприятия пользователя.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function myEvents(Request $request)
    {
        $user = $request->user();

        // Получаем все доступные мероприятия пользователя
        $query = $user->accessibleEvents()
            ->with([
                'category',
                'categories' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('sort_order');
                },
                'speakers' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('pivot_sort_order', 'asc')
                          ->orderBy('last_name', 'asc');
                }
            ])
            ->where('events.is_active', true);

        // Простая сортировка по дате получения доступа (новые сверху)
        $query->orderBy('event_user.access_granted_at', 'desc');

        // Получаем все события (без пагинации сначала)
        $allEvents = $query->get();
        
        // Получаем ID live мероприятий для приоритета
        $liveEventIds = $user->liveEvents->pluck('id')->toArray();
        
        // Сортируем: live мероприятия в начале, остальные по дате доступа
        $sortedEvents = $allEvents->sortBy(function ($event) use ($liveEventIds) {
            $isLive = in_array($event->id, $liveEventIds) ? 0 : 1; // 0 для live (в начале)
            return [$isLive, $event->access_granted_at ? -strtotime($event->access_granted_at) : 0];
        })->values();

        $perPage = 20;
        $currentPage = request()->input('page', 1);
        $total = $sortedEvents->count();
        $offset = ($currentPage - 1) * $perPage;
        $items = $sortedEvents->slice($offset, $perPage)->values();

        $events = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );

        $totalCount = $total;
        $liveCount = $user->liveEvents->count();

        return Inertia::render('MyEvents', [
            'events' => $events,
            'totalCount' => $totalCount,
            'liveCount' => $liveCount,
        ]);
    }
}