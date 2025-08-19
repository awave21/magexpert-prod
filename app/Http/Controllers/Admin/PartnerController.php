<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Illuminate\Support\Str;

class PartnerController extends Controller
{
    /**
     * Отображение списка партнеров.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'per_page']);
        
        $query = Partner::query();
        
        // Поиск по названию или описанию
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        
        // Пагинация
        $perPage = $request->input('per_page', 10);
        $partners = $query->latest()->paginate($perPage)->withQueryString();
        
        // Добавляем URL к логотипам
        $partners->through(function ($partner) {
            $partner->logo_url = $partner->logo_path ? Storage::url($partner->logo_path) : null;
            return $partner;
        });

        return Inertia::render('Admin/Partners', [
            'partners' => $partners,
            'filters' => $filters,
            'canManagePartners' => $request->user()->can('manage-partners'),
        ]);
    }

    /**
     * Сохранение нового партнера.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website_url' => 'nullable|url|max:255',
            'logo' => 'required|image|max:2048',
        ]);
        
        $partnerData = collect($validated)->except('logo')->all();

        // Создаем партнера с временным значением для logo_path
        $partner = new Partner($partnerData);
        $partner->logo_path = 'placeholder';
        $partner->save();

        // Теперь, когда у нас есть ID, обрабатываем и сохраняем логотип
        $this->processAndSaveLogo($partner, $request->file('logo'));
        
        return redirect()->route('admin.partners')->with('success', 'Партнер успешно добавлен');
    }

    /**
     * Обновление информации о партнере.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Partner  $partner
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Partner $partner)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website_url' => 'nullable|url|max:255',
            'logo' => 'required_if:delete_logo,1|nullable|image|max:2048',
            'delete_logo' => 'sometimes|in:0,1',
        ]);
        
        $updateData = collect($validated)->except(['logo', 'delete_logo'])->all();
        $partner->update($updateData);
        
        if ($request->hasFile('logo')) {
            $this->processAndSaveLogo($partner, $request->file('logo'));
        }
        
        return redirect()->route('admin.partners')->with('success', 'Информация о партнере успешно обновлена');
    }

    /**
     * Удаление партнера.
     *
     * @param  \App\Models\Partner  $partner
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Partner $partner)
    {
        $partnerDir = "partners/{$partner->id}";
        if (Storage::disk('public')->exists($partnerDir)) {
             Storage::disk('public')->deleteDirectory($partnerDir);
        }
        
        $partner->delete();
        
        return redirect()->route('admin.partners')->with('success', 'Партнер успешно удален');
    }

    /**
     * Обрабатывает и сохраняет логотип партнера.
     *
     * @param \App\Models\Partner $partner
     * @param \Illuminate\Http\UploadedFile $logoFile
     * @return void
     */
    protected function processAndSaveLogo(Partner $partner, $logoFile)
    {
        if ($partner->logo_path) {
            $this->deleteLogoFiles($partner->logo_path);
        }
        
        $dir = "partners/{$partner->id}";
        
        $manager = new ImageManager(new Driver());
        $img = $manager->read($logoFile);

        // Конвертируем в webp без изменения размера
        $encodedImage = $img->toWebp(90);

        $fileName = Str::slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.webp';
        $filePath = "$dir/$fileName";
        
        Storage::disk('public')->put($filePath, $encodedImage);
        
        $partner->logo_path = $filePath;
        $partner->save();
    }

    /**
     * Удаляет файл логотипа.
     *
     * @param string $logoPath
     * @return void
     */
    protected function deleteLogoFiles(string $logoPath)
    {
        $path = str_replace('/storage/', '', $logoPath);

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
} 