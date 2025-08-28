<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Category;
use App\Models\Speaker;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Bitrix24RegistrationService;
use App\Services\SendsayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class EventsController extends Controller
{
    protected PaymentService $paymentService;
    protected SendsayService $sendsayService;
    protected Bitrix24RegistrationService $bitrix24RegistrationService;

    public function __construct(
        PaymentService $paymentService,
        SendsayService $sendsayService,
        Bitrix24RegistrationService $bitrix24RegistrationService
    ) {
        $this->paymentService = $paymentService;
        $this->sendsayService = $sendsayService;
        $this->bitrix24RegistrationService = $bitrix24RegistrationService;
    }

    /** Список мероприятий */
    public function index(Request $request)
    {
        $query = Event::query()
            ->with([
                'category',
                'categories' => function ($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'speakers' => function ($query) {
                    $query->where('is_active', true)->select('speakers.*');
                }
            ])
            ->where('is_active', true);

        $filter = $request->input('filter', 'upcoming');
        if ($filter === 'upcoming') {
            $query->where('is_archived', false)
                ->where(function ($q) {
                    $q->where('end_date', '>=', now()->format('Y-m-d'))
                      ->orWhere('is_on_demand', true);
                });
        } elseif ($filter === 'archive') {
            $query->where(function ($q) {
                $q->where('is_archived', true)
                  ->orWhere(function ($q2) {
                      $q2->where('is_on_demand', false)
                         ->where('end_date', '<', now()->format('Y-m-d'));
                  });
            });
        }

        if ($request->filled('search')) {
            $search = Str::lower(trim($request->search));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(location) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('category') && $request->category !== '') {
            $categories = is_array($request->category) ? $request->category : [$request->category];
            $query->where(function ($q) use ($categories) {
                $q->whereHas('categories', function ($subQ) use ($categories) {
                    $subQ->whereIn('category_id', $categories);
                })->orWhereIn('category_id', $categories);
            });
        }

        if ($request->filled('type') && $request->type !== '') {
            $query->where('event_type', $request->type);
        }

        if ($request->filled('format') && $request->format !== '') {
            $query->where('format', $request->format);
        }

        $sortField = $request->input('sort', 'start_date');
        $allowedSortFields = [
            'title', 'start_date', 'end_date', 'price',
            'event_type', 'format', 'location', 'sort_order',
            'created_at', 'updated_at'
        ];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'start_date';
        }

        $defaultDirection = $filter === 'archive' ? 'desc' : 'asc';
        $sortDirection = $request->input('direction', $defaultDirection);
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc'])
            ? strtolower($sortDirection)
            : $defaultDirection;

        $query->orderBy($sortField, $sortDirection);

        $perPage = $request->input('per_page', 12);
        $events = $query->paginate($perPage)->withQueryString();

        $categories = Category::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
        $speakersCount = Speaker::where('is_active', true)->count();
        $randomSpeakers = Speaker::where('is_active', true)->inRandomOrder()->limit(3)
            ->get(['id', 'first_name', 'last_name', 'photo']);

        return Inertia::render('Events/Index', [
            'events' => $events,
            'categories' => $categories,
            'speakersCount' => $speakersCount,
            'randomSpeakers' => $randomSpeakers,
            'filters' => array_merge(
                $request->only(['search', 'category', 'type', 'format', 'sort', 'direction']),
                ['filter' => $filter]
            ),
        ]);
    }

    public function archive(Request $request)
    {
        return redirect()->route('events.index', ['filter' => 'archive']);
    }

    /** Детали мероприятия */
    public function show(Event $event)
    {
        if (!$event->is_active) {
            abort(404);
        }

        $event->load([
            'category',
            'categories' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            },
            'speakers' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('pivot_sort_order', 'asc')
                    ->orderBy('last_name', 'asc')
                    ->select('speakers.*');
            }
        ]);

        $user = auth()->user();
        $hasAccess = $user ? $event->hasUserAccess($user) : false;

        $eventData = $event->toArray();
        if (!$hasAccess) {
            unset($eventData['kinescope_id'], $eventData['kinescope_playlist_id']);
            $eventData['has_recording'] = $event->hasKinescopeRecord();
            $eventData['recording_type'] = $event->hasKinescopeRecord() ? $event->getKinescopeTypeLabel() : null;
        } else {
            $eventData['has_recording'] = $event->hasKinescopeRecord();
            $eventData['recording_type'] = $event->hasKinescopeRecord() ? $event->getKinescopeTypeLabel() : null;
            $eventData['embed_url'] = $event->getKinescopeEmbedUrl();
        }
        $eventData['user_has_access'] = $hasAccess;

        $categoryIds = $event->categories->pluck('id')->toArray();
        if (empty($categoryIds) && $event->category_id) {
            $categoryIds = [$event->category_id];
        }

        $relatedEvents = collect();
        if (!empty($categoryIds)) {
            $relatedEvents = Event::query()
                ->where('id', '!=', $event->id)
                ->where('is_active', true)
                ->where(function ($q) use ($categoryIds) {
                    $q->whereHas('categories', function ($subQ) use ($categoryIds) {
                        $subQ->whereIn('category_id', $categoryIds);
                    })->orWhereIn('category_id', $categoryIds);
                })
                ->limit(3)
                ->get();
        }
        // Считаем активные регистрации (кто реально занял место)
        $registeredCount = $event->users()
        ->wherePivot('is_active', true)
        // при желании можно сузить: ->wherePivotIn('payment_status', ['free','completed','unpaid'])
        ->count();

        $eventData['registered_count'] = $registeredCount;
        $eventData['available_spots'] = $event->max_quantity !== null
        ? max(0, $event->max_quantity - $registeredCount)
        : null;

        return Inertia::render('Events/Show', [
            'event' => $eventData,
            'relatedEvents' => $relatedEvents,
        ]);
    }

    /** Просмотр мероприятия (для авторизованных) */
    public function view(Event $event)
    {
        if (!$event->is_active) {
            abort(404);
        }
        if (!auth()->check()) {
            return redirect()->route('login');
        }
        $user = auth()->user();
        if (!$event->hasUserAccess($user)) {
            return redirect()->route('events.show', $event->slug)
                ->with('info', 'Для просмотра мероприятия необходимо зарегистрироваться');
        }

        $event->load([
            'category',
            'categories' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            },
            'speakers' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('pivot_sort_order', 'asc')
                    ->orderBy('last_name', 'asc')
                    ->select('speakers.*');
            }
        ]);

        $eventData = $event->toArray();
        $eventData['has_recording'] = $event->hasKinescopeRecord();
        $eventData['recording_type'] = $event->hasKinescopeRecord() ? $event->getKinescopeTypeLabel() : null;
        $eventData['embed_url'] = $event->getKinescopeEmbedUrl();
        $eventData['user_has_access'] = true;

        return Inertia::render('Events/View', [
            'event' => $eventData,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => trim($user->first_name . ' ' . $user->last_name),
            ],
        ]);
    }

    /** Проверка email */
    public function checkUserEmail(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $user = User::where('email', $request->email)->first();
        if ($user) {
            return response()->json([
                'success' => true,
                'exists' => true,
                'user' => ['id' => $user->id, 'email' => $user->email]
            ]);
        }
        return response()->json(['success' => true, 'exists' => false]);
    }

    /** Регистрация */
    public function register(Request $request, Event $event)
    {
        // Игнорируем event_id от фронта
        $request->request->remove('event_id');

        if (!$event->is_active) {
            return $this->errorResponse($request, 'Мероприятие недоступно');
        }
        if (!$event->registration_enabled) {
            return $this->errorResponse($request, 'Регистрация на мероприятие отключена');
        }
        if ($event->is_archived && !$event->hasKinescopeRecord()) {
            return $this->errorResponse($request, 'Запись мероприятия пока недоступна');
        }
        if (
            !$event->is_archived &&
            $event->format === 'offline' &&
            $event->start_date &&
            $event->start_date < now()->format('Y-m-d')
        ) {
            return $this->errorResponse($request, 'Мероприятие уже прошло');
        }

        // ===== Авторизованный: fast или on_demand — мгновенная регистрация как free
        if (auth()->check() && ($request->boolean('fast', false) || $event->is_on_demand)) {
            try {
                $user = auth()->user();

                if ($event->hasUserAccess($user)) {
                    return $this->successResponse($request, 'Вы уже зарегистрированы на это мероприятие');
                }

                $this->syncWithBitrix24Safe($event, $user, $request);

                if ($event->isPaid() && !$event->is_on_demand) {
                    return $this->handlePaidEventRegistration($event, $user, $request, '', false);
                }

                // on_demand ведём как бесплатное участие
                $event->users()->syncWithoutDetaching([
                    $user->id => [
                        'access_type'       => 'free',
                        'payment_status'    => 'free',
                        'access_granted_at' => now(),
                        'is_active'         => true,
                    ]
                ]);

                try {
                    $this->sendsayService->sendEventRegistrationEmail($event, $user, '', false);
                } catch (\Throwable $e) {
                    \Log::warning('Не удалось отправить email при быстрой регистрации', [
                        'event_id' => $event->id,
                        'user_id'  => $user->id,
                        'error'    => $e->getMessage()
                    ]);
                }

                return $this->successResponse($request, 'Регистрация прошла успешно!');
            } catch (\Throwable $e) {
                \Log::error('Ошибка быстрой регистрации: '.$e->getMessage(), ['event_id' => $event->id]);
                return $this->errorResponse($request, 'Ошибка при регистрации');
            }
        }

        // ===== Гость
        $isExistingUser = $request->boolean('existing_user', false);
        if ($isExistingUser) {
            $validator = Validator::make($request->all(), ['email' => 'required|email|max:255']);
        } else {
            $validator = Validator::make($request->all(), [
                'first_name'     => 'required|string|max:255',
                'last_name'      => 'required|string|max:255',
                'middle_name'    => 'nullable|string|max:255',
                'email'          => 'required|email|max:255',
                'phone'          => 'required|string|max:20',
                'city'           => 'required|string|max:255',
                'specialization' => 'nullable|string|max:255',
            ]);
        }
        if ($validator->fails()) {
            return $this->errorResponse($request, $validator->errors()->first());
        }

        try {
            $user = User::where('email', $request->email)->first();
            $isNewUser = false;
            $generatedPassword = '';

            if ($isExistingUser) {
                if (!$user) {
                    return $this->errorResponse($request, 'Пользователь с таким email не найден');
                }
            } else {
                if ($user) {
                    return $this->errorResponse($request, 'Пользователь уже существует');
                }
                $generatedPassword = Str::random(12);
                $isNewUser = true;
                $user = User::create([
                    'first_name'       => $request->first_name,
                    'last_name'        => $request->last_name,
                    'middle_name'      => $request->middle_name,
                    'email'            => $request->email,
                    'phone'            => $request->phone,
                    'city'             => $request->city,
                    'specialization'   => $request->specialization,
                    'password'         => Hash::make($generatedPassword),
                    'email_verified_at'=> now(),
                ]);
                // по желанию можно логинить
                // Auth::login($user);
                // $request->session()->regenerate();
            }

            if ($event->hasUserAccess($user)) {
                return $this->successResponse($request, 'Вы уже зарегистрированы на это мероприятие');
            }

            $this->syncWithBitrix24Safe($event, $user, $request);

            if ($event->is_on_demand) {
                // как бесплатное участие
                $event->users()->syncWithoutDetaching([
                    $user->id => [
                        'access_type'       => 'free',
                        'payment_status'    => 'free',
                        'access_granted_at' => now(),
                        'is_active'         => true,
                    ]
                ]);
            } elseif ($event->isPaid()) {
                return $this->handlePaidEventRegistration($event, $user, $request, $generatedPassword, $isNewUser);
            } else {
                $event->users()->syncWithoutDetaching([
                    $user->id => [
                        'access_type'       => 'free',
                        'payment_status'    => 'free',
                        'access_granted_at' => now(),
                        'is_active'         => true,
                    ]
                ]);
            }

            try {
                $this->sendsayService->sendEventRegistrationEmail($event, $user, $generatedPassword, $isNewUser);
            } catch (\Throwable $e) {
                \Log::warning('Не удалось отправить email о регистрации', [
                    'event_id' => $event->id,
                    'user_id'  => $user->id,
                    'error'    => $e->getMessage()
                ]);
            }

            return $this->successResponse($request, 'Регистрация успешна!');
        } catch (\Throwable $e) {
            \Log::error('Ошибка при регистрации: '.$e->getMessage(), [
                'event_id' => $event->id,
                'email'    => $request->email,
            ]);
            return $this->errorResponse($request, 'Ошибка регистрации. Попробуйте позже.');
        }
    }

    /** Вспомогательные методы */
    private function successResponse(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }
        return back()->with('success', $message);
    }

    private function errorResponse(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }
        return back()->withErrors(['event' => $message]);
    }

    /** Оплата */
    protected function handlePaidEventRegistration(Event $event, User $user, Request $request, string $generatedPassword = '', bool $isNewUser = false)
    {
        try {
            if ($isNewUser && !auth()->check()) {
                Auth::login($user);
                $request->session()->regenerate();
            }
            $this->syncWithBitrix24Safe($event, $user, $request);

            $result = $this->paymentService->createPaymentLink($event, $user, [
                'return_url' => route('events.show', $event->slug),
                'user_data' => [
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'middle_name' => $request->middle_name,
                    'phone' => $request->phone,
                    'city' => $request->city,
                    'specialization' => $request->specialization,
                ],
                'generated_password' => $generatedPassword,
                'is_new_user' => $isNewUser,
            ]);

            if (!$result['success']) {
                return back()->withErrors(['event' => $result['error']]);
            }
            return \Inertia\Inertia::location($result['payment_url']);
        } catch (\Throwable $e) {
            \Log::error('Ошибка платежа: '.$e->getMessage());
            return back()->withErrors(['event' => 'Ошибка при создании платежа']);
        }
    }

    /** Синхронизация с Bitrix24 */
    protected function syncWithBitrix24Safe(Event $event, User $user, Request $request): void
    {
        try {
            $payload = [
                'utm' => $request->only([
                    'utm_source','utm_medium','utm_campaign','utm_content','utm_term',
                ]),
                // on_demand теперь тоже "Зарегистрирован"
                'event_status' => $request->input('event_status', 'Зарегистрирован'),
            ];
            $this->bitrix24RegistrationService->syncRegistration($event, $user, $payload);
        } catch (\Throwable $e) {
            \Log::warning('Bitrix24 sync failed', [
                'event_id' => $event->id,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
