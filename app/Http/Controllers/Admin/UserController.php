<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with('roles');

        // Поиск
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('middle_name', 'like', "%{$search}%")
                  ->orWhere(function ($subQuery) use ($search) {
                      $subQuery->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                  })
                  ->orWhere(function ($subQuery) use ($search) {
                      $subQuery->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                  })
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Фильтр по роли
        if ($request->filled('role')) {
            $role = $request->role;
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        // Сортировка
        $sortField = $request->input('sort_field', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Пагинация
        $perPage = $request->input('per_page', 10);
        $paginator = $query->paginate($perPage)
            ->withQueryString();
            
        // Создаем кастомные ссылки для пагинации с русскими метками
        $paginator->setPath(route('admin.users'));
        $paginator->appends($request->except('page'));
        
        // Модифицируем ссылки пагинации
        $paginatorInfo = $paginator->toArray();
        
            // Добавляем полное имя и информацию об аватарке для каждого пользователя
    $userIds = array_column($paginatorInfo['data'], 'id');
    $userModels = User::whereIn('id', $userIds)->get()->keyBy('id');
    
    foreach ($paginatorInfo['data'] as &$user) {
        if (isset($userModels[$user['id']])) {
            $userModel = $userModels[$user['id']];
            $user['full_name'] = $userModel->getFullNameAttribute();
            $user['avatar'] = $userModel->avatar;
        } else {
            $user['full_name'] = '';
            $user['avatar'] = '';
        }
    }
        
        // Создаем ссылки с улучшенным форматированием
        $links = $this->formatPaginationLinks($paginator);
        $paginatorInfo['links'] = $links;
        
        // Обработка пустого набора данных
        if ($paginator->isEmpty() && $paginator->currentPage() > 1) {
            return redirect()->route('admin.users');
        }

        // Получаем все возможные роли для отображения в интерфейсе
        $roles = Role::all();

        // Текущий пользователь
        $currentUser = auth()->user();

        return Inertia::render('Admin/Users', [
            'users' => $paginatorInfo,
            'filters' => $request->only(['search', 'sort_field', 'sort_direction', 'role', 'per_page']),
            'roles' => $roles,
            'canManageUsers' => $currentUser->hasAnyRole(['admin', 'manager']),
            'canManageRoles' => $currentUser->hasRole('admin'),
            'isAdmin' => $currentUser->hasRole('admin'),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на редактирование пользователя
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для редактирования пользователей');
        }

        // Если пользователь - не админ, и пытается редактировать админа
        if (!$currentUser->hasRole('admin') && $user->hasRole('admin')) {
            abort(403, 'У вас нет прав для редактирования администраторов');
        }

        // Преобразуем строковое значение "true"/"false" в булево для поля delete_avatar
        if ($request->has('delete_avatar')) {
            $deleteAvatar = $request->input('delete_avatar');
            if ($deleteAvatar === 'true' || $deleteAvatar === '1') {
                $request->merge(['delete_avatar' => true]);
            } elseif ($deleteAvatar === 'false' || $deleteAvatar === '0') {
                $request->merge(['delete_avatar' => false]);
            }
        }

        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'email' => 'required|email|unique:users,email,' . $user->id,
                'company' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'avatar' => 'nullable|image|max:20480', // 20MB max
                'delete_avatar' => 'nullable|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Ошибка валидации:', [
                'errors' => $e->errors()
            ]);
            throw $e;
        }

        // Данные для обновления
        $userData = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'position' => $validated['position'] ?? null,
            'city' => $validated['city'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ];

        // Обработка аватара
        if ($request->hasFile('avatar')) {
            // Сохраняем новый аватар
            $this->processAndSaveAvatar($user, $request->file('avatar'));
        } elseif ($request->boolean('delete_avatar')) {
            // Удаляем аватар, если указано
            if ($user->avatar && !str_starts_with($user->avatar, 'http')) {
                $oldAvatarPath = str_replace('/storage/', '', $user->avatar);
                if (Storage::disk('public')->exists($oldAvatarPath)) {
                    Storage::disk('public')->delete($oldAvatarPath);
                }
            }
            $userData['avatar'] = null;
        }

        // Обновляем основные данные пользователя
        $user->update($userData);

        // Обработка ролей больше не производится в этом методе
        // if ($request->has('roles') && $currentUser->hasRole('admin')) {
        //     $roleIds = Role::whereIn('name', $request->roles)->pluck('id')->toArray();
        //     $user->roles()->sync($roleIds);
        // }

        return redirect()->route('admin.users.show', $user)->with('success', 'Пользователь успешно обновлен');
    }

    public function destroy(User $user)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на удаление пользователя
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для удаления пользователей');
        }

        // Если пользователь - не админ, и пытается удалить админа
        if (!$currentUser->hasRole('admin') && $user->hasRole('admin')) {
            abort(403, 'У вас нет прав для удаления администраторов');
        }

        if ($user->id === $currentUser->id) {
            return back()->with('error', 'Вы не можете удалить свой аккаунт');
        }

        $user->delete();

        return back()->with('success', 'Пользователь успешно удален');
    }
    
    /**
     * Отображает детальную информацию о пользователе.
     *
     * @param User $user
     * @return \Inertia\Response
     */
    public function show(User $user)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на просмотр пользователя
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для просмотра пользователей');
        }

        // Если пользователь - не админ, и пытается просмотреть админа
        if (!$currentUser->hasRole('admin') && $user->hasRole('admin')) {
            abort(403, 'У вас нет прав для просмотра администраторов');
        }

        // Загружаем полную информацию о пользователе
        $user->load('roles', 'events');

        // Получаем все возможные роли для отображения в интерфейсе
        $roles = Role::all();
        
        // Получаем все доступные события для добавления доступа
        $availableEvents = Event::where('is_active', true)
            ->where('is_archived', false)
            ->whereNotIn('id', $user->events->pluck('id'))
            ->orderBy('title')
            ->get(['id', 'title', 'short_description']);

        return Inertia::render('Admin/UserShow', [
            'user' => $user,
            'roles' => $roles,
            'userEvents' => $user->events,
            'availableEvents' => $availableEvents,
            'canManageUsers' => $currentUser->hasAnyRole(['admin', 'manager']),
            'canManageRoles' => $currentUser->hasRole('admin'),
            'canManageAccess' => $currentUser->hasAnyRole(['admin', 'manager']),
            'isAdmin' => $currentUser->hasRole('admin'),
        ]);
    }

    /**
     * Сохраняет нового пользователя.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на создание пользователя
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для создания пользователей');
        }

        // Валидация данных
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'company' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|max:20480', // 20MB max
            'password' => 'required|string|min:8|confirmed',
            'roles' => 'nullable|array',
        ]);

        // Создаем пользователя
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'position' => $validated['position'] ?? null,
            'city' => $validated['city'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => bcrypt($validated['password']),
        ]);
        
        // Обработка аватара
        if ($request->hasFile('avatar')) {
            $this->processAndSaveAvatar($user, $request->file('avatar'));
        }

        // Присваиваем роли, только если текущий пользователь - администратор
        if ($currentUser->hasRole('admin') && isset($validated['roles'])) {
            $roleIds = Role::whereIn('name', $validated['roles'])->pluck('id')->toArray();
            $user->roles()->sync($roleIds);
        } else {
            // Если не администратор, то присваиваем только роль пользователя
            $userRole = Role::where('name', 'user')->first();
            if ($userRole) {
                $user->roles()->sync([$userRole->id]);
            }
        }

        return redirect()->route('admin.users')->with('success', 'Пользователь успешно создан');
    }

    /**
     * Обрабатывает и сохраняет аватар пользователя
     *
     * @param User $user
     * @param \Illuminate\Http\UploadedFile $avatarFile
     * @return void
     */
    protected function processAndSaveAvatar(User $user, $avatarFile)
    {
        // Удаляем старый аватар, если он существует и это не http ссылка
        if ($user->avatar && !str_starts_with($user->avatar, 'http')) {
            $oldAvatarPath = str_replace('/storage/', '', $user->avatar);
            if (Storage::disk('public')->exists($oldAvatarPath)) {
                Storage::disk('public')->delete($oldAvatarPath);
            }
        }

        // Создаем директорию для пользователя, если она не существует
        $userDir = "users/{$user->id}";
        Storage::disk('public')->makeDirectory($userDir);

        // Создаем менеджер изображений с драйвером GD
        $manager = new ImageManager(new Driver());

        // Читаем и конвертируем изображение в WebP
        $img = $manager->read($avatarFile)->toWebp(90); // 90 - качество
        
        // Сохраняем изображение
        $fileName = time() . '_' . uniqid() . '.webp';
        $path = $userDir . '/' . $fileName;
        
        Storage::disk('public')->put($path, (string) $img);
        
        // Обновляем путь к аватару в модели
        $user->avatar = '/storage/' . $path;
        $user->save();
    }
    
    /**
     * Форматирует ссылки пагинации для улучшенного отображения
     * 
     * @param LengthAwarePaginator $paginator
     * @return array
     */
    protected function formatPaginationLinks($paginator)
    {
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $onEachSide = 1; // Сколько страниц показывать по бокам от текущей

        $links = [];
        
        // Добавляем кнопку "Предыдущая"
        $links[] = [
            'url' => $paginator->previousPageUrl(),
            'label' => '&laquo; Назад',
            'active' => false,
            'title' => 'Предыдущая страница'
        ];
        
        // Если нет страниц (пустой результат или одна страница)
        if ($lastPage <= 1) {
            $links[] = [
                'url' => null,
                'label' => '1',
                'active' => true,
            ];
        } else {
            // Начальные страницы
            $startPage = max(1, $currentPage - $onEachSide);
            $endPage = min($lastPage, $currentPage + $onEachSide);
            
            // Проверка на необходимость отображения первой страницы и многоточия
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
            
            // Центральные страницы
            for ($i = $startPage; $i <= $endPage; $i++) {
                $links[] = [
                    'url' => $paginator->url($i),
                    'label' => (string) $i,
                    'active' => $currentPage === $i,
                ];
            }
            
            // Проверка на необходимость отображения последней страницы и многоточия
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
        
        // Добавляем кнопку "Следующая"
        $links[] = [
            'url' => $paginator->nextPageUrl(),
            'label' => 'Вперед &raquo;',
            'active' => false,
            'title' => 'Следующая страница'
        ];
        
        return $links;
    }

    /**
     * Добавляет доступ пользователя к событию.
     *
     * @param Request $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeEventAccess(Request $request, User $user)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на управление доступами
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для управления доступами к событиям');
        }

        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'access_type' => 'required|in:free,paid,promotional,admin',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_id' => 'nullable|string|max:255',
            'payment_status' => 'required|in:free,pending,completed,failed,refunded',
            'access_granted_at' => 'nullable|date',
            'access_expires_at' => 'nullable|date|after:now',
            'is_active' => 'boolean',
        ]);

        // Проверяем, нет ли уже доступа к этому событию
        if ($user->events()->where('event_id', $validated['event_id'])->exists()) {
            return back()->withErrors(['event_id' => 'У пользователя уже есть доступ к этому событию']);
        }

        // Подготавливаем данные для pivot таблицы
        $pivotData = [
            'access_type' => $validated['access_type'],
            'payment_amount' => $validated['payment_amount'],
            'payment_id' => $validated['payment_id'],
            'payment_status' => $validated['payment_status'],
            'access_granted_at' => $validated['access_granted_at'] ?? now(),
            'access_expires_at' => $validated['access_expires_at'],
            'is_active' => $validated['is_active'] ?? true,
        ];

        // Добавляем доступ
        $user->events()->attach($validated['event_id'], $pivotData);

        return back()->with('success', 'Доступ к событию успешно добавлен');
    }

    /**
     * Обновляет доступ пользователя к событию.
     *
     * @param Request $request
     * @param User $user
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateEventAccess(Request $request, User $user, Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на управление доступами
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для управления доступами к событиям');
        }

        // Проверяем, есть ли доступ к этому событию
        if (!$user->events()->where('event_id', $event->id)->exists()) {
            return back()->withErrors(['error' => 'У пользователя нет доступа к этому событию']);
        }

        $validated = $request->validate([
            'access_type' => 'required|in:free,paid,promotional,admin',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_id' => 'nullable|string|max:255',
            'payment_status' => 'required|in:free,pending,completed,failed,refunded',
            'access_granted_at' => 'nullable|date',
            'access_expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        // Подготавливаем данные для обновления
        $pivotData = [
            'access_type' => $validated['access_type'],
            'payment_amount' => $validated['payment_amount'],
            'payment_id' => $validated['payment_id'],
            'payment_status' => $validated['payment_status'],
            'access_granted_at' => $validated['access_granted_at'],
            'access_expires_at' => $validated['access_expires_at'],
            'is_active' => $validated['is_active'] ?? true,
        ];

        // Обновляем доступ
        $user->events()->updateExistingPivot($event->id, $pivotData);

        return back()->with('success', 'Доступ к событию успешно обновлен');
    }

    /**
     * Переключает активность доступа пользователя к событию.
     *
     * @param User $user
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggleEventAccess(User $user, Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на управление доступами
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для управления доступами к событиям');
        }

        // Получаем текущий статус активности
        $pivot = $user->events()->where('event_id', $event->id)->first();
        
        if (!$pivot) {
            return back()->withErrors(['error' => 'У пользователя нет доступа к этому событию']);
        }

        $newStatus = !$pivot->pivot->is_active;

        // Обновляем статус
        $user->events()->updateExistingPivot($event->id, ['is_active' => $newStatus]);

        $message = $newStatus ? 'Доступ к событию активирован' : 'Доступ к событию отключен';
        
        return back()->with('success', $message);
    }

    /**
     * Удаляет доступ пользователя к событию.
     *
     * @param User $user
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyEventAccess(User $user, Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на управление доступами
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для управления доступами к событиям');
        }

        // Проверяем, есть ли доступ к этому событию
        if (!$user->events()->where('event_id', $event->id)->exists()) {
            return back()->withErrors(['error' => 'У пользователя нет доступа к этому событию']);
        }

        // Удаляем доступ
        $user->events()->detach($event->id);

        return back()->with('success', 'Доступ к событию успешно удален');
    }
} 