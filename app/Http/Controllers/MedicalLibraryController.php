<?php

namespace App\Http\Controllers;

use App\Models\MedicalLibrary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class MedicalLibraryController extends Controller
{
    /**
     * Отображение списка документов медицинской библиотеки.
     */
    public function index(Request $request)
    {
        $query = MedicalLibrary::query();

        // Поиск по названию (регистронезависимый)
        if ($request->filled('search')) {
            $search = Str::lower(trim($request->search));
            $query->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"]);
        }

        // Фильтр по языку
        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }

        // Фильтр по году публикации
        if ($request->filled('year')) {
            $query->whereYear('publication_date', $request->year);
        }

        // Сортировка
        $sort = $request->get('sort', 'publication_date');
        $direction = $request->get('direction', 'desc');
        
        // Валидация направления сортировки
        $allowedSorts = ['title', 'publication_date'];
        $allowedDirections = ['asc', 'desc'];
        
        if (in_array($sort, $allowedSorts) && in_array($direction, $allowedDirections)) {
            $query->orderBy($sort, $direction);
        } else {
            // Дефолтная сортировка если параметры невалидны
            $query->orderBy('publication_date', 'desc');
        }

        // Пагинация
        $documents = $query->paginate(12);

        // Получаем уникальные языки для фильтра
        $languages = MedicalLibrary::select('language')
            ->distinct()
            ->whereNotNull('language')
            ->orderBy('language')
            ->pluck('language');

        // Получаем уникальные годы для фильтра
        $years = MedicalLibrary::selectRaw('EXTRACT(YEAR FROM publication_date) as year')
            ->distinct()
            ->whereNotNull('publication_date')
            ->orderBy('year', 'desc')
            ->pluck('year');

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'languages' => $languages,
            'years' => $years,
            'filters' => [
                'search' => $request->search,
                'language' => $request->language,
                'year' => $request->year,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Показать конкретный документ - перенаправляем к файлу.
     */
    public function show(MedicalLibrary $document)
    {
        // Если есть файл, перенаправляем к нему
        if ($document->file_url) {
            return redirect($document->file_url);
        }
        
        // Если файла нет, возвращаем на страницу списка с ошибкой
        return redirect()->route('documents.index')
            ->with('error', 'Файл документа не найден.');
    }
} 