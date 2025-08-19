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

    public function __construct(PaymentService $paymentService, SendsayService $sendsayService, Bitrix24RegistrationService $bitrix24RegistrationService)
    {
        $this->paymentService = $paymentService;
        $this->sendsayService = $sendsayService;
        $this->bitrix24RegistrationService = $bitrix24RegistrationService;
    }
    /**
     * Отображает список всех мероприятий
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $query = Event::query()
            ->with([
                'category', 
                'categories' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('sort_order');
                },
                'speakers' => function($query) {
                    $query->where('is_active', true)
                          ->select('speakers.*'); // Загружаем все поля включая regalia
                }
            ])
            ->where('is_active', true);
            
        // Фильтр по архивным/предстоящим мероприятиям
        $filter = $request->input('filter', 'upcoming');
        if ($filter === 'upcoming') {
            $query->where('is_archived', false)
                ->where(function($q) {
                    $q->where('end_date', '>=', now()->format('Y-m-d'))
                      ->orWhere('is_on_demand', true);
                });
        } else if ($filter === 'archive') {
            $query->where(function($q) {
                $q->where('is_archived', true)
                  ->orWhere(function($q2) {
                      $q2->where('is_on_demand', false)
                         ->where('end_date', '<', now()->format('Y-m-d'));
                  });
            });
        }

        // Поиск (регистронезависимо) по названию, описанию, локации
        if ($request->filled('search')) {
            $search = Str::lower(trim($request->search));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(location) LIKE ?', ["%{$search}%"]);
            });
        }
        
        // Фильтр по категории (поддержка множественного выбора)
        if ($request->filled('category') && $request->category !== '') {
            $categories = is_array($request->category) ? $request->category : [$request->category];
            $query->where(function ($q) use ($categories) {
                // Поиск по новой системе категорий (many-to-many)
                $q->whereHas('categories', function ($subQ) use ($categories) {
                    $subQ->whereIn('category_id', $categories);
                })
                // ИЛИ по старой системе (one-to-many) для обратной совместимости  
                ->orWhereIn('category_id', $categories);
            });
        }
        
        // Фильтр по типу мероприятия
        if ($request->filled('type') && $request->type !== '') {
            $query->where('event_type', $request->type);
        }

        // Фильтр по формату
        if ($request->filled('format') && $request->format !== '') {
            $query->where('format', $request->format);
        }

        // Сортировка
        $sortField = $request->input('sort', 'start_date');

        // Проверка существования поля для сортировки
        $allowedSortFields = [
            'title', 'start_date', 'end_date', 'price', 
            'event_type', 'format', 'location', 'sort_order',
            'created_at', 'updated_at'
        ];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'start_date';
        }

        // Определяем направление сортировки в зависимости от фильтра
        $defaultDirection = 'asc';
        if ($filter === 'archive') {
            $defaultDirection = 'desc';
        } else if ($filter === 'upcoming') {
            $defaultDirection = 'asc';
        }

        $sortDirection = $request->input('direction', $defaultDirection);

        // Валидация направления сортировки
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? strtolower($sortDirection) : $defaultDirection;

        // Применяем сортировку
        $query->orderBy($sortField, $sortDirection);

        // Пагинация
        $perPage = $request->input('per_page', 12);
        $events = $query->paginate($perPage)->withQueryString();
        
        // Получаем список категорий для фильтра
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);
            
        // Получаем общее количество спикеров
        $speakersCount = Speaker::where('is_active', true)->count();
            
        // Получаем 3 случайных спикера для отображения на странице
        $randomSpeakers = Speaker::where('is_active', true)
            ->inRandomOrder()
            ->limit(3)
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

    /**
     * Редирект на основную страницу с фильтром архива
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function archive(Request $request)
    {
        return redirect()->route('events.index', ['filter' => 'archive']);
    }

    /**
     * Отображает детальную информацию о мероприятии
     *
     * @param Event $event
     * @return \Inertia\Response
     */
    public function show(Event $event)
    {
        // Проверяем, что мероприятие активно
        if (!$event->is_active) {
            abort(404);
        }
        
        // Загружаем связанные данные
        $event->load([
            'category',
            'categories' => function($query) {
                $query->where('is_active', true)
                      ->orderBy('sort_order');
            },
            'speakers' => function($query) {
                $query->where('is_active', true)
                      ->orderBy('pivot_sort_order', 'asc')
                      ->orderBy('last_name', 'asc')
                      ->select('speakers.*'); // Загружаем все поля включая regalia
            }
        ]);
        
        // Проверяем доступ пользователя к мероприятию
        $hasAccess = false;
        $user = auth()->user();
        if ($user) {
            $hasAccess = $event->hasUserAccess($user);
        }
        
        // Подготавливаем данные для фронтенда
        $eventData = $event->toArray();
        
        // Скрываем чувствительные данные если нет доступа
        if (!$hasAccess) {
            // Убираем ID записей Kinescope
            unset($eventData['kinescope_id']);
            unset($eventData['kinescope_playlist_id']);
            
            // Оставляем только информацию о том, что запись существует
            $eventData['has_recording'] = $event->hasKinescopeRecord();
            $eventData['recording_type'] = $event->hasKinescopeRecord() ? $event->getKinescopeTypeLabel() : null;
        } else {
            // Если есть доступ, добавляем информацию о записи
            $eventData['has_recording'] = $event->hasKinescopeRecord();
            $eventData['recording_type'] = $event->hasKinescopeRecord() ? $event->getKinescopeTypeLabel() : null;
            $eventData['embed_url'] = $event->getKinescopeEmbedUrl();
        }
        
        // Добавляем информацию о доступе
        $eventData['user_has_access'] = $hasAccess;
        
        // Получаем похожие мероприятия по категориям
        $categoryIds = $event->categories->pluck('id')->toArray();
        
        // Если нет категорий в новой системе, используем старую
        if (empty($categoryIds) && $event->category_id) {
            $categoryIds = [$event->category_id];
        }
        
        $relatedEvents = collect();
        if (!empty($categoryIds)) {
            $relatedEvents = Event::query()
                ->where('id', '!=', $event->id)
                ->where('is_active', true)
                ->where(function ($q) use ($categoryIds) {
                    // Поиск по новой системе категорий (many-to-many)
                    $q->whereHas('categories', function ($subQ) use ($categoryIds) {
                        $subQ->whereIn('category_id', $categoryIds);
                    })
                    // ИЛИ по старой системе (one-to-many) для обратной совместимости  
                    ->orWhereIn('category_id', $categoryIds);
                })
                ->limit(3)
                ->get();
        }

        return Inertia::render('Events/Show', [
            'event' => $eventData,
            'relatedEvents' => $relatedEvents,
        ]);
    }

    /**
     * Страница просмотра мероприятия (для авторизованных пользователей).
     *
     * @param Event $event
     * @return \Inertia\Response
     */
    public function view(Event $event)
    {
        // Проверяем, что мероприятие активно
        if (!$event->is_active) {
            abort(404);
        }
        
        // Проверяем авторизацию
        if (!auth()->check()) {
            return redirect()->route('login');
        }
        
        $user = auth()->user();
        
        // Проверяем доступ пользователя к мероприятию
        if (!$event->hasUserAccess($user)) {
            // Если нет доступа, перенаправляем на страницу мероприятия для регистрации
            return redirect()->route('events.show', $event->slug)
                ->with('info', 'Для просмотра мероприятия необходимо зарегистрироваться');
        }
        
        // Загружаем связанные данные
        $event->load([
            'category',
            'categories' => function($query) {
                $query->where('is_active', true)
                      ->orderBy('sort_order');
            },
            'speakers' => function($query) {
                $query->where('is_active', true)
                      ->orderBy('pivot_sort_order', 'asc')
                      ->orderBy('last_name', 'asc')
                      ->select('speakers.*');
            }
        ]);

        // Подготавливаем данные для фронтенда (с полным доступом)
        $eventData = $event->toArray();
        
        // Добавляем информацию о записи
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

    /**
     * Проверка существования пользователя по email
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkUserEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user) {
            return response()->json([
                'success' => true,
                'exists' => true,
                // Не возвращаем персональные данные для защиты конфиденциальности
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'exists' => false
        ]);
    }

    /**
     * Регистрация пользователя на мероприятие
     *
     * @param Request $request
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function register(Request $request, Event $event)
    {
        // Проверяем, что мероприятие активно и доступно для регистрации
        if (!$event->is_active) {
            return back()->withErrors(['event' => 'Мероприятие недоступно']);
        }

        if (!$event->registration_enabled) {
            return back()->withErrors(['event' => 'Регистрация на мероприятие отключена']);
        }

        // Для архивных мероприятий проверяем наличие записи
        if ($event->is_archived) {
            if (!$event->hasKinescopeRecord()) {
                return back()->withErrors(['event' => 'Запись мероприятия пока недоступна']);
            }
        }

        // Для офлайн мероприятий проверяем дату (только если не архивное)
        if (!$event->is_archived && $event->format === 'offline' && $event->start_date && $event->start_date < now()->format('Y-m-d')) {
            return back()->withErrors(['event' => 'Мероприятие уже прошло']);
        }

        // Быстрая регистрация для авторизованных пользователей (без ввода данных)
        if (auth()->check() && $request->boolean('fast', false)) {
            try {
                $user = auth()->user();

                // Уже есть доступ
                if ($event->hasUserAccess($user)) {
                    return back()->with('success', 'Вы уже зарегистрированы на это мероприятие');
                }

                // Синхронизация с Bitrix24 — не блокирует регистрацию
                $this->syncWithBitrix24Safe($event, $user, $request);

                // Платное мероприятие — создаем платеж и редиректим на оплату
                if ($event->isPaid()) {
                    return $this->handlePaidEventRegistration($event, $user, $request, '', false);
                }

                // Бесплатное — даем доступ сразу
                $event->users()->attach($user->id, [
                    'access_type' => 'free',
                    'payment_status' => 'free',
                    'access_granted_at' => now(),
                    'is_active' => true,
                ]);

                // Письмо пользователю (не критично)
                try {
                    $this->sendsayService->sendEventRegistrationEmail($event, $user, '', false);
                } catch (\Exception $e) {
                    \Log::warning('Не удалось отправить email о быстрой регистрации на мероприятие', [
                        'event_id' => $event->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                }

                return back()->with('success', 'Регистрация прошла успешно! Подробности отправлены на ваш email.');
            } catch (\Throwable $e) {
                \Log::error('Ошибка быстрой регистрации: ' . $e->getMessage(), [
                    'event_id' => $event->id,
                    'user_id' => auth()->id(),
                ]);
                return back()->withErrors(['event' => 'Не удалось выполнить регистрацию. Попробуйте позже.']);
            }
        }

        // Проверяем, является ли это регистрацией существующего пользователя
        $isExistingUser = $request->boolean('existing_user', false);
        
        if ($isExistingUser) {
            // Для существующих пользователей требуется только email
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
            ], [
                'email.required' => 'Поле "Email" обязательно для заполнения',
                'email.email' => 'Введите корректный email адрес',
            ]);
        } else {
            // Для новых пользователей требуются все поля
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'city' => 'required|string|max:255',
                'specialization' => 'nullable|string|max:255',
            ], [
                'first_name.required' => 'Поле "Имя" обязательно для заполнения',
                'last_name.required' => 'Поле "Фамилия" обязательно для заполнения',
                'email.required' => 'Поле "Email" обязательно для заполнения',
                'email.email' => 'Введите корректный email адрес',
                'phone.required' => 'Поле "Телефон" обязательно для заполнения',
                'city.required' => 'Поле "Город" обязательно для заполнения',
            ]);
        }

        if ($validator->fails()) {
            return back()->withErrors($validator->errors());
        }

        try {
            // Ищем или создаем пользователя
            $user = User::where('email', $request->email)->first();
            $isNewUser = false;
            $generatedPassword = '';
            
            if (!$user) {
                if ($isExistingUser) {
                    // Если флаг existing_user установлен, но пользователь не найден - ошибка
                    return back()->withErrors(['email' => 'Пользователь с таким email не найден']);
                }
                
                // Генерируем пароль для нового пользователя
                $generatedPassword = Str::random(12);
                $isNewUser = true;
                
                // Создаем нового пользователя
                $user = User::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'middle_name' => $request->middle_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'city' => $request->city,
                    'specialization' => $request->specialization,
                    'password' => Hash::make($generatedPassword),
                    'email_verified_at' => now(), // Считаем email подтвержденным при регистрации на мероприятие
                ]);
                
                // Автоматически авторизуем нового пользователя
                Auth::login($user);
                
                // Регенерируем сессию для безопасности
                $request->session()->regenerate();
            } else if (!$isExistingUser) {
                // Если пользователь найден, но флаг existing_user не установлен - ошибка
                return back()->withErrors(['email' => 'Пользователь с таким email уже существует']);
            }

            // Проверяем, не зарегистрирован ли пользователь уже на это мероприятие
            if ($event->hasUserAccess($user)) {
                return back()->withErrors(['event' => 'Вы уже зарегистрированы на это мероприятие']);
            }

            // Синхронизация с Bitrix24 (контакт/сделка/мероприятие) — не блокирует регистрацию
            $this->syncWithBitrix24Safe($event, $user, $request);

            // Если мероприятие платное, создаем платежную ссылку
            if ($event->isPaid()) {
                return $this->handlePaidEventRegistration($event, $user, $request, $generatedPassword, $isNewUser);
            }

            // Для бесплатных мероприятий регистрируем сразу
            $event->users()->attach($user->id, [
                'access_type' => 'free',
                'payment_status' => 'free',
                'access_granted_at' => now(),
                'is_active' => true,
            ]);

            // Загружаем спикеров для отправки в письме
            $event->load([
                'speakers' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('pivot_sort_order', 'asc')
                          ->orderBy('last_name', 'asc');
                }
            ]);

            // Отправляем email уведомление
            try {
                $this->sendsayService->sendEventRegistrationEmail($event, $user, $generatedPassword, $isNewUser);
            } catch (\Exception $e) {
                \Log::warning('Не удалось отправить email о регистрации на мероприятие', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                // Не прерываем выполнение, так как пользователь уже зарегистрирован
            }

            $message = $isNewUser 
                ? 'Регистрация прошла успешно! Вы автоматически авторизованы в системе. Подробности отправлены на ваш email.'
                : 'Регистрация прошла успешно! Подробности отправлены на ваш email.';
            
            return back()->with('success', $message);

        } catch (\Exception $e) {
            \Log::error('Ошибка при регистрации на мероприятие: ' . $e->getMessage());
            return back()->withErrors(['event' => 'Произошла ошибка при регистрации. Попробуйте позже.']);
        }
    }

    /**
     * Обработать регистрацию на платное мероприятие
     *
     * @param Event $event
     * @param User $user
     * @param Request $request
     * @param string $generatedPassword
     * @param bool $isNewUser
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function handlePaidEventRegistration(Event $event, User $user, Request $request, string $generatedPassword = '', bool $isNewUser = false)
    {
        try {
            // Если это новый пользователь, авторизуем его
            if ($isNewUser && !auth()->check()) {
                Auth::login($user);
                $request->session()->regenerate();
            }
            // Синхронизация с Bitrix24 (контакт/сделка/мероприятие) — не блокирует платеж
            $this->syncWithBitrix24Safe($event, $user, $request);

            // Перевод стадий сделки отключен по требованиям — ничего не делаем

            // Создаем платежную ссылку
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

            // Для AJAX запросов возвращаем JSON с payment_url
            if ($request->wantsJson() || $request->header('X-Inertia')) {
                \Log::info('Отправляем payment_url в flash-данных', [
                    'payment_url' => $result['payment_url'],
                    'payment_id' => $result['payment_id']
                ]);
                
                // Для Inertia запросов используем location для перенаправления
                return \Inertia\Inertia::location($result['payment_url']);
            }
            
            // Для обычных запросов перенаправляем на страницу оплаты
            return redirect($result['payment_url'])
                ->with('info', 'Для завершения регистрации необходимо произвести оплату');

        } catch (\Exception $e) {
            \Log::error('Ошибка создания платежной ссылки: ' . $e->getMessage(), [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()->withErrors(['event' => 'Произошла ошибка при создании платежной ссылки. Попробуйте позже.']);
        }
    }

    /**
     * Безопасный вызов синхронизации с Bitrix24. Ошибки пишем в лог, не прерываем поток.
     */
    protected function syncWithBitrix24Safe(Event $event, User $user, Request $request): void
    {
        try {
            $payload = [
                'utm_source' => $request->input('utm_source'),
                'utm_medium' => $request->input('utm_medium'),
                'utm_campaign' => $request->input('utm_campaign'),
                'utm_content' => $request->input('utm_content'),
                'utm_term' => $request->input('utm_term'),
                'city' => $request->input('city'),
                'phone' => $request->input('phone'),
                'position' => $request->input('position'),
                'specialization' => $request->input('specialization'),
                'format' => $event->format,
                // Для бесплатных регистраций статус сразу «Оплачено», для платных – «Ожидает оплаты»
                'event_status' => $event->isPaid() ? 'Ожидает оплаты' : 'Оплачено',
            ];

            $result = $this->bitrix24RegistrationService->syncRegistration($event, $user, $payload);

            if (!($result['success'] ?? false)) {
                \Log::channel(config('bitrix24.log_channel', 'bitrix24'))
                    ->warning('Bitrix24: синхронизация неуспешна', [
                        'event_id' => $event->id,
                        'user_id' => $user->id,
                        'error' => $result['error'] ?? null,
                    ]);
            } else {
                // Можно положить идентификаторы в сессию для фронта (Inertia flash)
                session()->flash('bitrix24', [
                    'contactId' => $result['contactId'] ?? null,
                    'dealId' => $result['dealId'] ?? null,
                    'itemId' => $result['itemId'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::channel(config('bitrix24.log_channel', 'bitrix24'))
                ->error('Bitrix24: исключение при синхронизации', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
        }
    }
} 