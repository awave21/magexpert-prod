<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedicalLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class MedicalLibraryController extends Controller
{
    /**
     * Отображает список материалов медицинской библиотеки.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'language', 'per_page']);
        
        $query = MedicalLibrary::query();
        
        // Применяем фильтры
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('language')) {
            $query->where('language', $request->input('language'));
        }
        
        // Сортировка по дате публикации (новые сначала)
        $query->orderBy('publication_date', 'desc');
        
        // Пагинация
        $perPage = $request->input('per_page', 10);
        $library = $query->paginate($perPage)->withQueryString();
        
        // Добавляем URL к файлам и изображениям
        $library->through(function ($item) {
            $item->file_url = $item->file_path ? Storage::url($item->file_path) : null;
            $item->image_url = $item->image_path ? Storage::url($item->image_path) : null;
            return $item;
        });
        
        // Текущий пользователь
        $currentUser = auth()->user();
        
        return Inertia::render('Admin/MedicalLibrary', [
            'library' => $library,
            'filters' => $filters,
            'canManageLibrary' => $currentUser->hasAnyRole(['admin', 'manager', 'editor']),
        ]);
    }

    /**
     * Сохраняет новый материал в медицинской библиотеке.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на создание материала
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для создания материалов');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'publication_date' => 'required|date',
            'language' => 'required|string|max:10',
            'file' => 'required|file|max:20480', // Максимум 20MB
            'image' => 'nullable|image|max:5120', // Максимум 5MB
        ]);
        
        $libraryData = collect($validated)->except(['file', 'image'])->all();
        
        // Создаем экземпляр модели, но не сохраняем в БД
        $library = new MedicalLibrary($libraryData);
        // Добавляем временное значение для not-null поля
        $library->file_path = 'placeholder';
        // Сохраняем модель, чтобы получить ID
        $library->save();

        if ($request->hasFile('file')) {
            $this->processAndSaveFile($library, $request->file('file'));
        }
        
        if ($request->hasFile('image')) {
            $this->processAndSaveImage($library, $request->file('image'));
        }
        
        return redirect()->route('admin.medical-library')->with('success', 'Материал успешно добавлен');
    }

    /**
     * Обновляет указанный материал в медицинской библиотеке.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\MedicalLibrary  $medicalLibrary
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, MedicalLibrary $medicalLibrary)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на редактирование материала
        if (!$currentUser->hasAnyRole(['admin', 'manager', 'editor'])) {
            abort(403, 'У вас нет прав для редактирования материалов');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'publication_date' => 'required|date',
            'language' => 'required|string|max:10',
            'file' => 'nullable|file|max:20480', // Максимум 20MB
            'image' => 'nullable|image|max:5120', // Максимум 5MB
            'delete_file' => 'sometimes|in:0,1',
            'delete_image' => 'sometimes|in:0,1',
        ]);
        
        $updateData = collect($validated)->except(['file', 'image', 'delete_file', 'delete_image'])->all();
        $medicalLibrary->update($updateData);

        // Обработка файла
        if ($request->hasFile('file')) {
            $this->processAndSaveFile($medicalLibrary, $request->file('file'));
        } elseif ((int) $request->input('delete_file') === 1) {
            if ($medicalLibrary->file_path) {
                Storage::disk('public')->delete($medicalLibrary->file_path);
                $medicalLibrary->file_path = null;
                $medicalLibrary->save();
            }
        }

        // Обработка изображения
        if ($request->hasFile('image')) {
            $this->processAndSaveImage($medicalLibrary, $request->file('image'));
        } elseif ((int) $request->input('delete_image') === 1) {
            if ($medicalLibrary->image_path) {
                $this->deleteImageFiles($medicalLibrary->image_path);
                $medicalLibrary->image_path = null;
                $medicalLibrary->save();
            }
        }
        
        return redirect()->route('admin.medical-library')->with('success', 'Материал успешно обновлен');
    }

    /**
     * Удаляет указанный материал из медицинской библиотеки.
     *
     * @param  \App\Models\MedicalLibrary  $medicalLibrary
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(MedicalLibrary $medicalLibrary)
    {
        $currentUser = auth()->user();
        
        // Проверяем права на удаление материала
        if (!$currentUser->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для удаления материалов');
        }

        $itemDir = "medical-library/{$medicalLibrary->id}";
        if (Storage::disk('public')->exists($itemDir)) {
             Storage::disk('public')->deleteDirectory($itemDir);
        }
        
        $medicalLibrary->delete();
        
        return redirect()->route('admin.medical-library')->with('success', 'Материал успешно удален');
    }

    /**
     * Обрабатывает и сохраняет файл материала.
     *
     * @param \App\Models\MedicalLibrary $item
     * @param \Illuminate\Http\UploadedFile $file
     * @return void
     */
    protected function processAndSaveFile(MedicalLibrary $item, $file)
    {
        if ($item->file_path && $item->file_path !== 'placeholder') {
            Storage::disk('public')->delete($item->file_path);
        }

        $dir = "medical-library/{$item->id}/files";
        $fileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $filePath = $file->storeAs($dir, $fileName, 'public');
        
        $item->file_path = $filePath;
        $item->save();
    }

    /**
     * Обрабатывает и сохраняет изображение материала.
     *
     * @param \App\Models\MedicalLibrary $item
     * @param \Illuminate\Http\UploadedFile $image
     * @return void
     */
    protected function processAndSaveImage(MedicalLibrary $item, $image)
    {
        if ($item->image_path) {
            $this->deleteImageFiles($item->image_path);
        }
        
        $dir = "medical-library/{$item->id}/images";
        
        $manager = new ImageManager(new Driver());
        $img = $manager->read($image);
        
        // Конвертируем в webp без изменения размера
        $encodedImage = $img->toWebp(90);

        $fileName = Str::slug(pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.webp';
        $filePath = "$dir/$fileName";
        
        Storage::disk('public')->put($filePath, $encodedImage);
        
        $item->image_path = $filePath;
        $item->save();
    }

    /**
     * Удаляет файл изображения.
     *
     * @param string $imagePath
     * @return void
     */
    protected function deleteImageFiles(string $imagePath)
    {
        $path = str_replace('/storage/', '', $imagePath);

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
