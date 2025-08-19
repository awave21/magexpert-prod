<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Category;
use App\Models\Speaker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    /**
     * Отображает список мероприятий
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    
    {
        $query = Event::query()->with(['category', 'categories', 'speakers']);

        // Поиск
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Фильтр по активности
        if ($request->filled('status') && $request->status !== '') {
            $status = $request->status === 'active';
            $query->where('is_active', $status);
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
        
        // Фильтр по типу оплаты
        if ($request->filled('payment') && $request->payment !== '') {
            if ($request->payment === 'paid') {
                $query->where('is_paid', true);
            } elseif ($request->payment === 'free') {
                $query->where('is_paid', false);
            }
        }
        
        // Фильтр по формату
        if ($request->filled('format') && $request->format !== '') {
            $query->where('format', $request->format);
        }
        
        // Фильтр по архивности
        if ($request->filled('archive') && $request->archive !== '') {
            if ($request->archive === 'archived') {
                $query->where('is_archived', true);
            } elseif ($request->archive === 'active') {
                $query->where('is_archived', false);
            }
        }
        
        // Фильтр по регистрации
        if ($request->filled('registration') && $request->registration !== '') {
            if ($request->registration === 'enabled') {
                $query->where('registration_enabled', true);
            } elseif ($request->registration === 'disabled') {
                $query->where('registration_enabled', false);
            }
        }
        
        // Фильтр по прямой трансляции
        if ($request->filled('live') && $request->live !== '') {
            if ($request->live === 'live') {
                $query->where('is_live', true);
            } elseif ($request->live === 'not_live') {
                $query->where('is_live', false);
            }
        }

        // Сортировка
        $sortField = $request->input('sort_field', 'start_date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Пагинация
        $perPage = $request->input('per_page', 10);
        $paginator = $query->paginate($perPage)
            ->withQueryString();
            
        // Создаем кастомные ссылки для пагинации
        $paginator->setPath(route('admin.events'));
        $paginator->appends($request->except('page'));
        
        // Форматируем ссылки пагинации
        $paginatorInfo = $paginator->toArray();
        $paginatorInfo['links'] = $this->formatPaginationLinks($paginator);
        
        // Обработка пустого набора данных
        if ($paginator->isEmpty() && $paginator->currentPage() > 1) {
            return redirect()->route('admin.events');
        }

        // Получаем список категорий для фильтра
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);
            
        // Получаем список активных спикеров
        $speakers = Speaker::where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'position', 'company', 'photo']);

        // Текущий пользователь
        $currentUser = auth()->user();

        return Inertia::render('Admin/Events', [
            'activeTab' => 'events',
            'events' => $paginatorInfo,
            'categories' => $categories,
            'speakers' => $speakers,
            'filters' => $request->only(['search', 'sort_field', 'sort_direction', 'status', 'category', 'type', 'payment', 'format', 'archive', 'registration', 'live', 'per_page']),
            'canManageEvents' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
            'canManageCategories' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
            'categoriesData' => null,
        ]);
    }

    /**
     * Поиск событий для API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $search = $request->input('q', '');
        $limit = $request->input('limit', 20);

        if (empty($search)) {
            return response()->json([]);
        }

        $events = Event::query()
            ->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            })
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title', 'start_date', 'location']);

        $formattedEvents = $events->map(function ($event) {
            return [
                'value' => $event->id,
                'text' => $event->title,
                'meta' => [
                    'date' => $event->start_date ? \Carbon\Carbon::parse($event->start_date)->format('d.m.Y') : null,
                    'location' => $event->location,
                ]
            ];
        });

        return response()->json($formattedEvents);
    }

    /**
     * Сохраняет новое мероприятие
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на создание мероприятия
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для создания мероприятий');
        }

        // Валидация данных
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:events,slug',
            'start_date' => $request->boolean('is_on_demand') ? 'nullable|date' : 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'end_time' => 'nullable|date_format:H:i',
            'is_on_demand' => 'boolean',
            'event_type' => 'required|string|max:50',
            'short_description' => 'nullable|string',
            'full_description' => 'nullable|string',
            'topic' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'is_paid' => 'boolean',
            'show_price' => 'boolean',
            'format' => 'nullable|string|max:50',
            'registration_enabled' => 'boolean',
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'is_archived' => 'boolean',
            'speakers' => 'nullable|array',
            'speakers.*.id' => 'exists:speakers,id',
            'speakers.*.role' => 'nullable|string|max:100',
            'speakers.*.topic' => 'nullable|string|max:255',
            'speakers.*.sort_order' => 'nullable|integer|min:0',
            'kinescope_id' => 'nullable|string|max:255',
            'kinescope_playlist_id' => 'nullable|string|max:255',
            'kinescope_type' => 'nullable|string|in:video,playlist',
            'is_live' => 'boolean',
            'letter_draft_id' => 'nullable|string|max:255',
            'groupsensay' => 'nullable|string|max:255',
        ];
        
        // Добавляем правило для изображения только если оно загружается
        if ($request->hasFile('image')) {
            $rules['image'] = 'image|mimes:jpeg,png,gif,webp|max:20480';
        }
        
        $validated = $request->validate($rules);

        // Генерируем slug, если он не был предоставлен
        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($validated['title']);
        }

        // Обрабатываем даты - убеждаемся что они в правильном формате
        if (!empty($validated['start_date'])) {
            $validated['start_date'] = \Carbon\Carbon::parse($validated['start_date'])->format('Y-m-d');
        }
        if (!empty($validated['end_date'])) {
            $validated['end_date'] = \Carbon\Carbon::parse($validated['end_date'])->format('Y-m-d');
        }

        // Удаляем изображение, спикеров и категории из валидированных данных, чтобы обработать отдельно
        $imageFile = $request->file('image');
        $speakers = $request->input('speakers', []);
        $categoriesIds = $request->input('categories', []);
        
        if (isset($validated['image'])) {
            unset($validated['image']);
        }
        
        if (isset($validated['speakers'])) {
            unset($validated['speakers']);
        }
        
        if (isset($validated['categories'])) {
            unset($validated['categories']);
        }

        // Создаем мероприятие
        $event = Event::create($validated);

        // Обрабатываем загрузку изображения, если оно есть
        if ($imageFile) {
            $this->processAndSaveImage($event, $imageFile);
        }
        
        // Перенос изображений из временных черновиков в папку события и обновление HTML
        $this->relocateEditorImagesForEvent($event);

        // Связываем категории с мероприятием
        if (!empty($categoriesIds)) {
            $event->categories()->sync($categoriesIds);
        } else {
            // Если не указаны категории через новый интерфейс, но есть основная категория
            if ($event->category_id) {
                $event->categories()->sync([$event->category_id]);
            }
        }
        
        // Связываем спикеров с мероприятием
        if (!empty($speakers)) {
            $speakerData = [];
            foreach ($speakers as $speaker) {
                $speakerData[$speaker['id']] = [
                    'role' => $speaker['role'] ?? null,
                    'topic' => $speaker['topic'] ?? null,
                    'sort_order' => $speaker['sort_order'] ?? 0
                ];
            }
            $event->speakers()->sync($speakerData);
        }

        // Удаляем неиспользуемые изображения из контента и черновиков
        $this->cleanupUnusedContentImages($event);
        $this->cleanupDraftImagesForRequest($request, (string) ($event->full_description ?? ''));

        return redirect()->route('admin.events')->with('success', 'Мероприятие успешно создано');
    }

    /**
     * Обновляет данные мероприятия
     *
     * @param Request $request
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на редактирование мероприятия
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для редактирования мероприятий');
        }

        // Валидация данных
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:events,slug,' . $event->id,
            'start_date' => $request->boolean('is_on_demand') ? 'nullable|date' : 'required|date',
            'start_time' => 'nullable|string',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'end_time' => 'nullable|string',
            'is_on_demand' => 'boolean',
            'event_type' => 'required|string|max:50',
            'short_description' => 'nullable|string',
            'full_description' => 'nullable|string',
            'topic' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'is_paid' => 'boolean',
            'show_price' => 'boolean',
            'format' => 'nullable|string|max:50',
            'registration_enabled' => 'boolean',
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'is_archived' => 'boolean',
            'delete_image' => 'sometimes|boolean',
            'speakers' => 'nullable|array',
            'speakers.*.id' => 'exists:speakers,id',
            'speakers.*.role' => 'nullable|string|max:100',
            'speakers.*.topic' => 'nullable|string|max:255',
            'speakers.*.sort_order' => 'nullable|integer|min:0',
            'kinescope_id' => 'nullable|string|max:255',
            'kinescope_playlist_id' => 'nullable|string|max:255',
            'kinescope_type' => 'nullable|string|in:video,playlist',
            'is_live' => 'boolean',
            'letter_draft_id' => 'nullable|string|max:255',
            'groupsensay' => 'nullable|string|max:255',
        ];
        
        // Добавляем правило для изображения только если оно загружается
        if ($request->hasFile('image')) {
            $rules['image'] = 'image|mimes:jpeg,png,gif,webp|max:20480';
        }
        
        $validated = $request->validate($rules);
        
        // Генерируем slug, если он не был предоставлен
        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($validated['title'], $event);
        }

        // Обрабатываем даты - убеждаемся что они в правильном формате
        if (!empty($validated['start_date'])) {
            $validated['start_date'] = \Carbon\Carbon::parse($validated['start_date'])->format('Y-m-d');
        }
        if (!empty($validated['end_date'])) {
            $validated['end_date'] = \Carbon\Carbon::parse($validated['end_date'])->format('Y-m-d');
        }

        // Данные для обновления мероприятия, исключая изображение, флаги, спикеров и категории
        $updateData = collect($validated)->except(['image', 'delete_image', 'speakers', 'categories'])->all();
        $event->update($updateData);

        // Перенос изображений из временных черновиков в папку события и обновление HTML (на случай остатков черновиков)
        $this->relocateEditorImagesForEvent($event);
        
        // Обновляем категории
        $categoriesIds = $request->input('categories', []);
        if (!empty($categoriesIds)) {
            $event->categories()->sync($categoriesIds);
        } else {
            // Если не указаны категории через новый интерфейс, но есть основная категория
            if ($event->category_id) {
                $event->categories()->sync([$event->category_id]);
            } else {
                $event->categories()->detach();
            }
        }

        // Обработка загрузки нового изображения (имеет приоритет)
        if ($request->file('image')) {
            $this->processAndSaveImage($event, $request->file('image'));
        } 
        // Обработка удаления изображения, если новое не загружено
        elseif ($request->boolean('delete_image')) {
            if ($event->image && !str_starts_with($event->image, 'http')) {
                $oldImagePath = str_replace('/storage/', '', $event->image);
                if (Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }
            $event->image = null;
            $event->save(); // Сохраняем, чтобы обнулить путь к изображению
        }
        
        // Обновляем связи со спикерами
        $speakers = $request->input('speakers', []);
        if (!empty($speakers)) {
            $speakerData = [];
            foreach ($speakers as $speaker) {
                $speakerData[$speaker['id']] = [
                    'role' => $speaker['role'] ?? null,
                    'topic' => $speaker['topic'] ?? null,
                    'sort_order' => $speaker['sort_order'] ?? 0
                ];
            }
            $event->speakers()->sync($speakerData);
        } else {
            // Если спикеры не указаны, удаляем все связи
            $event->speakers()->detach();
        }

        // Удаляем неиспользуемые изображения из контента и черновиков
        $this->cleanupUnusedContentImages($event);
        $this->cleanupDraftImagesForRequest($request, (string) ($event->full_description ?? ''));

        return back()->with('success', 'Мероприятие успешно обновлено');
    }

    /**
     * Удаляет мероприятие
     *
     * @param Event $event
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на удаление мероприятия
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для удаления мероприятий');
        }

        // Удаляем изображение мероприятия из хранилища, если оно существует
        if ($event->image && !str_starts_with($event->image, 'http')) {
            $imagePath = str_replace('/storage/', '', $event->image);
            if (Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }
        }

        // Удаляем изображения из описания (events/{id}/content/*)
        $contentDir = "events/{$event->id}/content";
        if (\Storage::disk('public')->exists($contentDir)) {
            \Storage::disk('public')->deleteDirectory($contentDir);
        }

        $event->delete();

        return back()->with('success', 'Мероприятие успешно удалено');
    }

    /**
     * Отображает детальную информацию о мероприятии
     *
     * @param Event $event
     * @return \Inertia\Response
     */
    public function show(Event $event)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на просмотр мероприятия
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для просмотра мероприятий');
        }

        return Inertia::render('Admin/EventShow', [
            'event' => $event->load(['category', 'categories', 'speakers']),
            'canManageEvents' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
        ]);
    }

    /**
     * Генерирует уникальный slug для мероприятия
     *
     * @param string $title
     * @param Event|null $event
     * @return string
     */
    protected function generateUniqueSlug(string $title, ?Event $event = null): string
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = Event::where('slug', $slug);
            if ($event) {
                // При обновлении, исключаем текущее мероприятие из проверки
                $query->where('id', '!=', $event->id);
            }
            if (!$query->exists()) {
                break;
            }
            $slug = $originalSlug . '-' . $counter++;
        }
        return $slug;
    }

    /**
     * Обрабатывает и сохраняет изображение мероприятия
     * 
     * @param Event $event
     * @param \Illuminate\Http\UploadedFile $image
     * @return void
     */
    protected function processAndSaveImage(Event $event, $image)
    {
        // Удаляем старое изображение, если оно существует и это не http ссылка
        if ($event->image && !str_starts_with($event->image, 'http')) {
            $oldImagePath = str_replace('/storage/', '', $event->image);
            if (Storage::disk('public')->exists($oldImagePath)) {
                Storage::disk('public')->delete($oldImagePath);
            }
        }

        // Создаем директорию для мероприятия, если она не существует
        $eventDir = "events/{$event->id}";
        Storage::disk('public')->makeDirectory($eventDir);

        // Создаем менеджер изображений с драйвером GD
        $manager = new ImageManager(new Driver());

        // Читаем изображение
        $img = $manager->read($image);
        
        // Сохраняем изображение в формате webp
        $fileName = time() . '.webp';
        $imagePath = $eventDir . '/' . $fileName;
        
        // Сохраняем в формате webp с качеством 90%
        Storage::disk('public')->put($imagePath, $img->toWebp(90));
        
        // Обновляем путь к изображению в модели
        $event->image = '/storage/' . $imagePath;
        $event->save();
    }

    /**
     * Ищет изображения из черновиков в HTML описания и переносит их в папку события, обновляя ссылки
     */
    protected function relocateEditorImagesForEvent(Event $event): void
    {
        $html = (string) ($event->full_description ?? '');
        if ($html === '') {
            return;
        }

        // Ищем все src у <img>
        if (!preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            return;
        }

        $urls = $matches[1] ?? [];
        if (empty($urls)) return;

        $map = [];
        $targetDir = "events/{$event->id}/content";
        \Storage::disk('public')->makeDirectory($targetDir);

        foreach ($urls as $url) {
            $onlyPath = parse_url($url, PHP_URL_PATH) ?? $url;
            if (!is_string($onlyPath) || !str_starts_with($onlyPath, '/storage/')) {
                continue;
            }
            $path = str_replace('/storage/', '', $onlyPath);
            // Переносим только из черновиков/старой папки редактора
            if (!str_starts_with($path, 'events/drafts/') && !str_starts_with($path, 'events/editor/')) {
                continue;
            }
            if (!\Storage::disk('public')->exists($path)) {
                continue;
            }

            $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'webp';
            $newName = time() . '-' . Str::random(8) . '.' . $ext;
            $newPath = $targetDir . '/' . $newName;

            // Гарантируем уникальность
            while (\Storage::disk('public')->exists($newPath)) {
                $newName = time() . '-' . Str::random(8) . '.' . $ext;
                $newPath = $targetDir . '/' . $newName;
            }

            \Storage::disk('public')->move($path, $newPath);
            $map[$url] = '/storage/' . $newPath;
        }

        if (!empty($map)) {
            $event->full_description = strtr($html, $map);
            $event->save();
        }
    }

    /**
     * Удаляет файлы в папке контента события, не упомянутые в full_description
     */
    protected function cleanupUnusedContentImages(Event $event): void
    {
        $dir = "events/{$event->id}/content";
        if (!\Storage::disk('public')->exists($dir)) {
            return;
        }
        $files = \Storage::disk('public')->files($dir);
        if (empty($files)) return;

        $html = (string) ($event->full_description ?? '');
        $usedPaths = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $onlyPath = parse_url($url, PHP_URL_PATH) ?? $url;
                if (is_string($onlyPath) && str_starts_with($onlyPath, '/storage/')) {
                    $p = str_replace('/storage/', '', $onlyPath);
                    $usedPaths[$p] = true;
                }
            }
        }

        foreach ($files as $file) {
            if (!isset($usedPaths[$file])) {
                \Storage::disk('public')->delete($file);
            }
        }
    }

    /**
     * Удаляет неиспользуемые изображения из черновой папки текущего редактора (по IP)
     */
    protected function cleanupDraftImagesForRequest(Request $request, string $html): void
    {
        $root = 'events/drafts/' . Str::slug($request->ip());
        if (!\Storage::disk('public')->exists($root)) {
            return;
        }
        $files = \Storage::disk('public')->allFiles($root);
        if (empty($files)) return;

        $used = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $onlyPath = parse_url($url, PHP_URL_PATH) ?? $url;
                if (is_string($onlyPath) && str_starts_with($onlyPath, '/storage/')) {
                    $p = str_replace('/storage/', '', $onlyPath);
                    $used[$p] = true;
                }
            }
        }

        foreach ($files as $file) {
            if (!isset($used[$file])) {
                \Storage::disk('public')->delete($file);
            }
        }
    }

    /**
     * Прием загрузки изображений из WYSIWYG редактора
     */
    public function uploadEditorImage(Request $request)
    {
        $currentUser = auth()->user();
        if (!$currentUser || !$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            return response()->json(['message' => 'Недостаточно прав'], 403);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:20480'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Неверный файл',
                'errors' => $validator->errors()
            ], 422);
        }

        $imageFile = $request->file('image');

        // Папка для изображений из редактора
        $eventId = $request->input('event_id');
        $dir = $eventId ? "events/{$eventId}/content" : 'events/drafts/' . Str::slug($request->ip()) . '/' . date('Y/m');
        \Storage::disk('public')->makeDirectory($dir);

        $manager = new ImageManager(new Driver());
        $img = $manager->read($imageFile);

        $fileName = time() . '-' . Str::random(8) . '.webp';
        $path = $dir . '/' . $fileName;

        \Storage::disk('public')->put($path, $img->toWebp(90));

        return response()->json([
            'url' => '/storage/' . $path
        ], 201);
    }

    /**
     * Удаляет изображение, загруженное редактором
     */
    public function deleteEditorImage(Request $request)
    {
        $currentUser = auth()->user();
        if (!$currentUser || !$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            return response()->json(['message' => 'Недостаточно прав'], 403);
        }

        $request->validate([
            'url' => 'required|string',
        ]);

        $url = $request->input('url');
        if (!is_string($url) || !str_starts_with($url, '/storage/')) {
            return response()->json(['message' => 'Некорректный путь'], 422);
        }

        $path = str_replace('/storage/', '', $url);

        // Безопасность: ограничиваемся папкой events
        if (!str_starts_with($path, 'events/')) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        if (\Storage::disk('public')->exists($path)) {
            \Storage::disk('public')->delete($path);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Форматирует ссылки пагинации для Inertia
     *
     * @param LengthAwarePaginator $paginator
     * @return array
     */
    protected function formatPaginationLinks($paginator)
    {
        $links = [];
        
        // Добавляем ссылку на первую страницу
        $links[] = [
            'url' => $paginator->url(1),
            'label' => '«',
            'active' => false,
            'page' => 1
        ];
        
        // Добавляем ссылку на предыдущую страницу
        $links[] = [
            'url' => $paginator->previousPageUrl(),
            'label' => '‹',
            'active' => false,
            'page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null
        ];
        
        // Получаем диапазон страниц для отображения
        $window = 2;
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        
        $startPage = max(1, $currentPage - $window);
        $endPage = min($lastPage, $currentPage + $window);
        
        // Добавляем страницы в диапазоне
        for ($page = $startPage; $page <= $endPage; $page++) {
            $links[] = [
                'url' => $paginator->url($page),
                'label' => (string) $page,
                'active' => $page === $currentPage,
                'page' => $page
            ];
        }
        
        // Добавляем ссылку на следующую страницу
        $links[] = [
            'url' => $paginator->nextPageUrl(),
            'label' => '›',
            'active' => false,
            'page' => $paginator->hasMorePages() ? $currentPage + 1 : null
        ];
        
        // Добавляем ссылку на последнюю страницу
        $links[] = [
            'url' => $paginator->url($lastPage),
            'label' => '»',
            'active' => false,
            'page' => $lastPage
        ];
        
        return $links;
    }
} 