<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\SpeakerController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\MedicalLibraryController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\EventRegistrationController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\MedicalLibraryController as PublicMedicalLibraryController;
use App\Http\Controllers\ExhibitionController; 
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DaDataController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Публичные маршруты
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Сервисный маршрут для обновления CSRF-токена (обход 419)
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/csrf-token', function () {
        // Регенерируем токен сессии, чтобы получить свежий
        request()->session()->regenerateToken();
        $token = csrf_token();

        return response()->json([
            'csrf_token' => $token,
        ])->withCookie(cookie(
            name: 'XSRF-TOKEN',
            value: $token,
            minutes: 0, // сессионная
            path: '/',
            domain: null,
            secure: config('session.secure', false),
            httpOnly: false, // должен быть доступен JS
            raw: false,
            sameSite: 'Lax'
        ))
          ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    })->name('csrf.token');
});

// Маршруты для мероприятий (публичная часть)
Route::get('/events', [EventsController::class, 'index'])->name('events.index');
Route::get('/events/archive', [EventsController::class, 'archive'])->name('events.archive');
Route::get('/events/{event:slug}', [EventsController::class, 'show'])->name('events.show');

// API маршруты для регистрации на мероприятия
Route::post('/api/check-user-email', [EventsController::class, 'checkUserEmail'])->name('api.check-user-email');
Route::post('/events/{event:slug}/register', [EventsController::class, 'register'])->name('events.register');

// API маршрут для поиска городов через DaData
Route::post('/api/dadata/cities', [DaDataController::class, 'searchCities'])
    ->middleware(['throttle:60,1'])
    ->name('api.dadata.cities');

// Маршруты для платежей
Route::prefix('payment')->name('payment.')->group(function () {
    // Создание платежа для мероприятия
    Route::post('/events/{event:slug}/create', [PaymentController::class, 'createPayment'])->name('create');
    
    // Проверка статуса платежа
    Route::get('/{payment}/status', [PaymentController::class, 'checkStatus'])->name('status');
    
    // Статичные страницы результатов оплаты
    Route::get('/success', [PaymentController::class, 'staticSuccess'])->name('success');
    Route::get('/failed', [PaymentController::class, 'staticFailed'])->name('failed');
});

// Маршрут для просмотра мероприятий в личном кабинете (требует авторизации)
Route::get('/my-events/{event:slug}', [EventsController::class, 'view'])
    ->middleware(['auth'])
    ->name('my-events.view');

// Маршруты для медицинской библиотеки (публичная часть)
Route::get('/documents', [PublicMedicalLibraryController::class, 'index'])->name('documents.index');
Route::get('/documents/{document}', [PublicMedicalLibraryController::class, 'show'])->name('documents.show');

// Маршруты для виртуальной выставки
Route::get('/exhibition', [ExhibitionController::class, 'index'])->name('exhibition.index');

// Маршрут для страницы контактов
Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Добавляем маршрут profile.show
    Route::get('/profile/show', [ProfileController::class, 'show'])->name('profile.show');
    
    // Маршрут для страницы сертификатов
    Route::get('/certificates', function () {
        return Inertia::render('Certificates');
    })->name('certificates');
    
    // Маршрут для страницы всех доступных мероприятий пользователя
    Route::get('/my-events', [DashboardController::class, 'myEvents'])->name('my-events');
});

// Маршруты для админки
Route::prefix('admin')->middleware(['auth', 'role:admin,editor,manager'])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    
    // Маршрут для страницы пользователей (доступно всем с ролями admin, editor, manager)
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users');
    
    // Маршрут для просмотра карточки пользователя
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    
    // Маршруты для спикеров
    Route::get('/speakers', [SpeakerController::class, 'index'])->name('admin.speakers');
    Route::get('/speakers/{speaker}', [SpeakerController::class, 'show'])->name('admin.speakers.show');
    Route::post('/speakers', [SpeakerController::class, 'store'])->name('admin.speakers.store');
    Route::put('/speakers/{speaker}', [SpeakerController::class, 'update'])->name('admin.speakers.update');
    Route::delete('/speakers/{speaker}', [SpeakerController::class, 'destroy'])->name('admin.speakers.destroy');
    
    // Маршруты для мероприятий
    Route::get('/events', [EventController::class, 'index'])->name('admin.events');
    Route::get('/events/search', [EventController::class, 'search'])->name('admin.events.search');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('admin.events.show');
    Route::post('/events', [EventController::class, 'store'])->name('admin.events.store');
    Route::put('/events/{event}', [EventController::class, 'update'])->name('admin.events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('admin.events.destroy');
    // Загрузка изображений из редактора описания
    Route::post('/events/upload-image', [EventController::class, 'uploadEditorImage'])->name('admin.events.upload-image');
    // Удаление изображений редактора
    Route::post('/events/delete-image', [EventController::class, 'deleteEditorImage'])->name('admin.events.delete-image');
    
    // Маршруты для категорий
    Route::get('/categories', [CategoryController::class, 'index'])->name('admin.categories');
    Route::post('/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('admin.categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');
    
    // Маршруты для медицинской библиотеки
    Route::get('/medical-library', [MedicalLibraryController::class, 'index'])->name('admin.medical-library');
    Route::post('/medical-library', [MedicalLibraryController::class, 'store'])->name('admin.medical-library.store');
    Route::put('/medical-library/{medicalLibrary}', [MedicalLibraryController::class, 'update'])->name('admin.medical-library.update');
    Route::delete('/medical-library/{medicalLibrary}', [MedicalLibraryController::class, 'destroy'])->name('admin.medical-library.destroy');
    
    // Маршруты для партнеров
    Route::get('/partners', [PartnerController::class, 'index'])->name('admin.partners');
    Route::post('/partners', [PartnerController::class, 'store'])->name('admin.partners.store');
    Route::put('/partners/{partner}', [PartnerController::class, 'update'])->name('admin.partners.update');
    Route::delete('/partners/{partner}', [PartnerController::class, 'destroy'])->name('admin.partners.destroy');
    
    // Маршруты для управления пользователями (только для админов и менеджеров)
    Route::middleware('role:admin,manager')->group(function () {
        // Создание пользователя
        Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        
        // Маршруты для управления ролями пользователей
        Route::get('/users/{user}/roles', [UserRoleController::class, 'edit'])->name('admin.users.roles.edit');
        Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->name('admin.users.roles.update');
        Route::post('/users/{user}/roles/add', [UserRoleController::class, 'addRole'])->name('admin.users.roles.add');
        Route::delete('/users/{user}/roles/{role}', [UserRoleController::class, 'removeRole'])->name('admin.users.roles.remove');
        
        // Маршруты для редактирования и удаления пользователей (только для админов и менеджеров)
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        
        // Маршруты для управления доступами пользователей к событиям
        Route::post('/users/{user}/events', [AdminUserController::class, 'storeEventAccess'])->name('admin.users.events.store');
        Route::put('/users/{user}/events/{event}', [AdminUserController::class, 'updateEventAccess'])->name('admin.users.events.update');
        Route::put('/users/{user}/events/{event}/toggle', [AdminUserController::class, 'toggleEventAccess'])->name('admin.users.events.toggle');
        Route::delete('/users/{user}/events/{event}', [AdminUserController::class, 'destroyEventAccess'])->name('admin.users.events.destroy');

        // Регистрации пользователей на мероприятия
        Route::get('/event-registrations', [EventRegistrationController::class, 'index'])->name('admin.event-registrations');
    });

    // Уведомления
    Route::middleware(['auth', 'role:admin,editor,manager'])->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::delete('/notifications', [NotificationController::class, 'clearAll'])->name('notifications.clear');
    });
});

Route::get('/game', [GameController::class, 'index'])->name('game');
Route::post('/api/test-websocket', [GameController::class, 'testWebSocket']);
Route::post('/api/test-notification', [\App\Http\Controllers\Admin\NotificationController::class, 'testNotification']);

require __DIR__.'/auth.php';
