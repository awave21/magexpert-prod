<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EventRegistrationController extends Controller
{
    /**
     * Список регистраций пользователей на мероприятия с фильтром по мероприятию.
     */
    public function index(Request $request)
    {
        $query = DB::table('event_user')
            ->join('users', 'users.id', '=', 'event_user.user_id')
            ->join('events', 'events.id', '=', 'event_user.event_id')
            ->select([
                'event_user.user_id',
                'event_user.event_id',
                'event_user.access_type',
                'event_user.payment_amount',
                'event_user.payment_id',
                'event_user.payment_status',
                'event_user.access_granted_at',
                'event_user.access_expires_at',
                'event_user.is_active',
                'users.first_name',
                'users.last_name',
                'users.middle_name',
                'users.email',
                'users.company',
                'users.position',
                'users.city',
                'users.avatar',
                'events.title as event_title',
                'events.slug as event_slug',
                'events.start_date as event_start_date',
                'events.start_time as event_start_time',
            ]);

        // Фильтр по мероприятию
        if ($request->filled('event_id')) {
            $query->where('event_user.event_id', $request->integer('event_id'));
        }

        // Поиск по пользователю/email
        if ($request->filled('search')) {
            $search = trim($request->get('search'));
            $query->where(function ($q) use ($search) {
                $q->where('users.first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('users.last_name', 'ILIKE', "%{$search}%")
                  ->orWhere('users.middle_name', 'ILIKE', "%{$search}%")
                  ->orWhere('users.email', 'ILIKE', "%{$search}%");
            });
        }

        // Сортировка
        $sort = $request->get('sort', 'access_granted_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = [
            'access_granted_at',
            'user',
            'email',
            'event_start',
            'payment_status',
        ];
        $allowedDirections = ['asc', 'desc'];

        if (!in_array($sort, $allowedSorts)) {
            $sort = 'access_granted_at';
        }
        if (!in_array($direction, $allowedDirections)) {
            $direction = 'desc';
        }

        switch ($sort) {
            case 'user':
                $query->orderBy('users.last_name', $direction)
                      ->orderBy('users.first_name', $direction);
                break;
            case 'email':
                $query->orderBy('users.email', $direction);
                break;
            case 'event_start':
                $query->orderBy('events.start_date', $direction)
                      ->orderBy('events.start_time', $direction);
                break;
            case 'payment_status':
                $query->orderBy('event_user.payment_status', $direction);
                break;
            default:
                $query->orderBy('event_user.access_granted_at', $direction);
        }

        // Пагинация
        $perPage = (int) $request->get('per_page', 20);
        $paginator = $query->paginate($perPage)->withQueryString();

        // Преобразование элементов для фронтенда
        $items = collect($paginator->items())->map(function ($row) {
            $fullName = trim(implode(' ', array_filter([
                $row->last_name,
                $row->first_name,
                $row->middle_name,
            ])));

            return [
                'key' => $row->user_id . '-' . $row->event_id,
                'user' => [
                    'id' => $row->user_id,
                    'full_name' => $fullName,
                    'email' => $row->email,
                    'company' => $row->company,
                    'position' => $row->position,
                    'city' => $row->city,
                    'avatar' => $row->avatar,
                ],
                'event' => [
                    'id' => $row->event_id,
                    'title' => $row->event_title,
                    'slug' => $row->event_slug,
                    'start_date' => $row->event_start_date,
                    'start_time' => $row->event_start_time,
                ],
                'access' => [
                    'type' => $row->access_type,
                    'type_label' => \App\Models\Event::getAccessTypeLabel($row->access_type),
                    'payment_status' => $row->payment_status,
                    'payment_status_label' => \App\Models\Event::getPaymentStatusLabel($row->payment_status),
                    'payment_amount' => $row->payment_amount,
                    'payment_id' => $row->payment_id,
                    'granted_at' => $row->access_granted_at,
                    'expires_at' => $row->access_expires_at,
                    'is_active' => (bool) $row->is_active,
                ],
            ];
        })->toArray();

        $registrations = new LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path()]
        );

        // Готовим данные пагинации с локализованными ссылками
        $paginatorInfo = $registrations->toArray();
        $paginatorInfo['links'] = $this->formatPaginationLinks($registrations);

        // Список мероприятий для фильтра
        $events = Event::query()
            ->orderBy('title')
            ->get(['id', 'title']);

        return Inertia::render('Admin/EventRegistrations', [
            'registrations' => $paginatorInfo,
            'events' => $events,
            'filters' => [
                'search' => $request->get('search'),
                'event_id' => $request->get('event_id'),
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Форматирует ссылки пагинации (как в админке) с русскими метками.
     */
    protected function formatPaginationLinks(LengthAwarePaginator $paginator): array
    {
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $onEachSide = 1;

        $links = [];

        // Prev
        $links[] = [
            'url' => $paginator->previousPageUrl(),
            'label' => '&laquo; Назад',
            'active' => false,
            'title' => 'Предыдущая страница',
        ];

        if ($lastPage <= 1) {
            $links[] = [
                'url' => null,
                'label' => '1',
                'active' => true,
            ];
        } else {
            $startPage = max(1, $currentPage - $onEachSide);
            $endPage = min($lastPage, $currentPage + $onEachSide);

            if ($startPage > 1) {
                $links[] = [
                    'url' => $paginator->url(1),
                    'label' => '1',
                    'active' => false,
                ];
                if ($startPage > 2) {
                    $links[] = [
                        'url' => null,
                        'label' => '...',
                        'active' => false,
                    ];
                }
            }

            for ($i = $startPage; $i <= $endPage; $i++) {
                $links[] = [
                    'url' => $paginator->url($i),
                    'label' => (string) $i,
                    'active' => $currentPage === $i,
                ];
            }

            if ($endPage < $lastPage) {
                if ($endPage < $lastPage - 1) {
                    $links[] = [
                        'url' => null,
                        'label' => '...',
                        'active' => false,
                    ];
                }
                $links[] = [
                    'url' => $paginator->url($lastPage),
                    'label' => (string) $lastPage,
                    'active' => $currentPage === $lastPage,
                ];
            }
        }

        // Next
        $links[] = [
            'url' => $paginator->nextPageUrl(),
            'label' => 'Вперед &raquo;',
            'active' => false,
            'title' => 'Следующая страница',
        ];

        return $links;
    }
}


