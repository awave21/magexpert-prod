<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Speaker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SpeakerController extends Controller
{
    /**
     * Отображает список спикеров
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $query = Speaker::query();

        // Поиск
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Фильтр по активности
        if ($request->filled('status') && $request->status !== '') {
            $status = $request->status === 'active';
            $query->where('is_active', $status);
        }

        // Сортировка
        $sortField = $request->input('sort_field', 'sort_order');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Пагинация
        $perPage = $request->input('per_page', 10);
        $paginator = $query->paginate($perPage)
            ->withQueryString();
            
        // Создаем кастомные ссылки для пагинации
        $paginator->setPath(route('admin.speakers'));
        $paginator->appends($request->except('page'));
        
        // Форматируем ссылки пагинации
        $paginatorInfo = $paginator->toArray();
        $links = $this->formatPaginationLinks($paginator);
        $paginatorInfo['links'] = $links;
        
        // Обработка пустого набора данных
        if ($paginator->isEmpty() && $paginator->currentPage() > 1) {
            return redirect()->route('admin.speakers');
        }

        // Текущий пользователь
        $currentUser = auth()->user();

        return Inertia::render('Admin/Speakers', [
            'speakers' => $paginatorInfo,
            'filters' => $request->only(['search', 'sort_field', 'sort_direction', 'status', 'per_page']),
            'canManageSpeakers' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
        ]);
    }

    /**
     * Сохраняет нового спикера
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на создание спикера
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для создания спикеров');
        }

        // Валидируем обязательные данные
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'regalia' => 'nullable|string',
            'description' => 'nullable|string',
            'position' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0'
        ]);

        // Если загружен файл фото, валидируем его отдельно
        if ($request->hasFile('photo')) {
            $request->validate([
                'photo' => 'image|mimes:jpeg,png,gif,webp|max:20480',
            ]);
        }

        // Удаляем фото из валидированных данных, чтобы обработать отдельно
        $photoFile = $request->file('photo');
        if (isset($validated['photo'])) {
            unset($validated['photo']);
        }

        // Создаем спикера
        $speaker = Speaker::create($validated);

        // Обрабатываем загрузку фото, если оно есть
        if ($photoFile) {
            $this->processAndSavePhoto($speaker, $photoFile);
        }

        return redirect()->route('admin.speakers')->with('success', 'Спикер успешно создан');
    }

    /**
     * Обновляет данные спикера
     *
     * @param Request $request
     * @param Speaker $speaker
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Speaker $speaker)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на редактирование спикера
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для редактирования спикеров');
        }

        // Валидируем обязательные данные
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'regalia' => 'nullable|string',
            'description' => 'nullable|string',
            'position' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'delete_photo' => 'sometimes|boolean',
        ]);

        // Если загружен файл фото, валидируем его отдельно
        if ($request->hasFile('photo')) {
            $request->validate([
                'photo' => 'image|mimes:jpeg,png,gif,webp|max:20480',
            ]);
        }
        
        // Данные для обновления спикера, исключая фото и флаги
        $updateData = $request->except(['photo', 'delete_photo', '_method']);
        $speaker->update($updateData);

        // Обработка загрузки нового фото (имеет приоритет)
        if ($request->hasFile('photo')) {
            $this->processAndSavePhoto($speaker, $request->file('photo'));
        } 
        // Обработка удаления фото, если новое не загружено
        elseif ($request->has('delete_photo') && $request->boolean('delete_photo')) {
            if ($speaker->photo && !str_starts_with($speaker->photo, 'http')) {
                $oldPhotoPath = str_replace('/storage/', '', $speaker->photo);
                if (Storage::disk('public')->exists($oldPhotoPath)) {
                    Storage::disk('public')->delete($oldPhotoPath);
                }
            }
            $speaker->photo = null;
            $speaker->save(); // Сохраняем, чтобы обнулить путь к фото
        }

        return back()->with('success', 'Спикер успешно обновлен');
    }

    /**
     * Удаляет спикера
     *
     * @param Speaker $speaker
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Speaker $speaker)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на удаление спикера
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для удаления спикеров');
        }

        // Удаляем фото спикера из хранилища, если оно существует
        if ($speaker->photo && !str_starts_with($speaker->photo, 'http')) {
            $photoPath = str_replace('/storage/', '', $speaker->photo);
            if (Storage::disk('public')->exists($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }
        }

        $speaker->delete();

        return back()->with('success', 'Спикер успешно удален');
    }

    /**
     * Отображает детальную информацию о спикере
     *
     * @param Speaker $speaker
     * @return \Inertia\Response
     */
    public function show(Speaker $speaker)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на просмотр спикера
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для просмотра спикеров');
        }

        return Inertia::render('Admin/SpeakerShow', [
            'speaker' => $speaker->load('events'),
            'canManageSpeakers' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
        ]);
    }

    /**
     * Обрабатывает и сохраняет фотографию спикера
     * 
     * @param Speaker $speaker
     * @param \Illuminate\Http\UploadedFile $photo
     * @return void
     */
    protected function processAndSavePhoto(Speaker $speaker, $photo)
    {
        // Удаляем старое фото, если оно существует и это не http ссылка
        if ($speaker->photo && !str_starts_with($speaker->photo, 'http')) {
            $oldPhotoPath = str_replace('/storage/', '', $speaker->photo);
            if (Storage::disk('public')->exists($oldPhotoPath)) {
                Storage::disk('public')->delete($oldPhotoPath);
            }
        }

        // Создаем директорию для спикера, если она не существует
        $speakerDir = "speakers/{$speaker->id}";
        Storage::disk('public')->makeDirectory($speakerDir);

        // Создаем менеджер изображений с драйвером GD
        $manager = new ImageManager(new Driver());

        // Читаем и обрабатываем изображение
        $image = $manager->read($photo);

        // Генерируем уникальное имя файла
        $fileName = 'photo_' . time() . '.webp';
        $fullPath = "{$speakerDir}/{$fileName}";

        // Преобразуем изображение в формат WebP с качеством 90% и сохраняем его
        Storage::disk('public')->put($fullPath, (string) $image->toWebp(90));

        // Обновляем путь к фото в модели спикера
        $speaker->photo = '/storage/' . $fullPath;
        $speaker->save();
    }

    /**
     * Форматирует ссылки пагинации для улучшенного отображения
     * 
     * @param LengthAwarePaginator $paginator
     * @return array
     */
    protected function formatPaginationLinks($paginator)
    {
        $window = 2; // Количество ссылок слева и справа от текущей страницы
        $lastPage = $paginator->lastPage();
        $currentPage = $paginator->currentPage();
        
        $links = [];
        
        // Ссылка на первую страницу
        $links[] = [
            'url' => $paginator->url(1),
            'label' => '&laquo;',
            'active' => false,
        ];
        
        // Ссылка на предыдущую страницу
        $links[] = [
            'url' => $paginator->previousPageUrl(),
            'label' => '&lsaquo;',
            'active' => false,
        ];

        // Формирование диапазона страниц
        $from = max(1, $currentPage - $window);
        $to = min($lastPage, $currentPage + $window);
        
        if ($from > 1) {
            $links[] = [
                'url' => null,
                'label' => '...',
                'active' => false,
            ];
        }
        
        for ($i = $from; $i <= $to; $i++) {
            $links[] = [
                'url' => $paginator->url($i),
                'label' => (string) $i,
                'active' => $currentPage === $i,
            ];
        }
        
        if ($to < $lastPage) {
            $links[] = [
                'url' => null,
                'label' => '...',
                'active' => false,
            ];
        }
        
        // Ссылка на следующую страницу
        $links[] = [
            'url' => $paginator->nextPageUrl(),
            'label' => '&rsaquo;',
            'active' => false,
        ];
        
        // Ссылка на последнюю страницу
        $links[] = [
            'url' => $paginator->url($lastPage),
            'label' => '&raquo;',
            'active' => false,
        ];
        
        return $links;
    }
} 