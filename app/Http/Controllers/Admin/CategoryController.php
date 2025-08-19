<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Отображает список категорий
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $query = Category::query();

        // Поиск
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
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
        $paginatedCategories = $query->paginate($perPage)
            ->withQueryString()
            ->through(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'is_active' => $category->is_active,
                    'sort_order' => $category->sort_order,
                    'events_count' => $category->events()->count(),
                    'active_events_count' => $category->activeEventsCount,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            });

        // Текущий пользователь
        $currentUser = auth()->user();

        // Категории для фильтра событий (нужны на странице в любом случае)
        $filterCategories = \App\Models\Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return Inertia::render('Admin/Events', [
            'activeTab' => 'categories',
            'categoriesData' => $paginatedCategories,
            'filters' => $request->only(['search', 'status', 'per_page']),
            'canManageCategories' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
            'canManageEvents' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
            'categories' => $filterCategories,
            'events' => null,
        ]);
    }

    /**
     * Сохраняет новую категорию
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        // Если slug не указан, генерируем его из имени
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        Category::create($validated);

        return redirect()->back()->with('success', 'Категория успешно создана');
    }

    /**
     * Обновляет существующую категорию
     *
     * @param Request $request
     * @param Category $category
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        // Если slug не указан, генерируем его из имени
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return redirect()->back()->with('success', 'Категория успешно обновлена');
    }

    /**
     * Удаляет категорию
     *
     * @param Category $category
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Category $category)
    {
        // Проверяем, есть ли связанные события
        if ($category->events()->count() > 0) {
            return redirect()->back()->with('error', 'Нельзя удалить категорию, к которой привязаны мероприятия');
        }

        $category->delete();

        return redirect()->back()->with('success', 'Категория успешно удалена');
    }
} 