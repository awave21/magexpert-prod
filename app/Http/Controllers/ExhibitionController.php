<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExhibitionController extends Controller
{
    /**
     * Отображение списка партнеров на виртуальной выставке.
     */
    public function index(Request $request)
    {
        // Получаем параметры поиска и фильтрации
        $search = $request->get('search', '');
        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction', 'asc');
        
        // Запрос для получения партнеров
        $query = Partner::query();
        
        // Поиск по названию или описанию
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('description', 'ILIKE', '%' . $search . '%');
            });
        }
        
        // Сортировка
        $allowedSorts = ['name', 'created_at'];
        if (in_array($sort, $allowedSorts)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
        }
        
        // Пагинация
        $partners = $query->paginate(12)->withQueryString();
        
        // Подсчет общего количества партнеров
        $partnersCount = Partner::count();
        
        return Inertia::render('Exhibition/Index', [
            'partners' => $partners,
            'partnersCount' => $partnersCount,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
            ]
        ]);
    }
} 