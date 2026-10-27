<?php

namespace App\Mcp\Support;

use App\Mcp\ToolException;
use App\Models\Event;
use App\Models\MedicalLibrary;
use App\Models\Partner;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Сохраняет картинки и документы так же, как админка: изображения — в webp, пути те же.
 */
class MediaStore
{
    /**
     * Что можно загрузить: ключ => [модель, поле, папка, как хранится путь].
     *
     * @var array<string, array{model: class-string<Model>, column: string, label: string}>
     */
    public const TARGETS = [
        'event_cover' => ['model' => Event::class, 'column' => 'image', 'label' => 'обложка мероприятия'],
        'speaker_photo' => ['model' => Speaker::class, 'column' => 'photo', 'label' => 'фото спикера'],
        'partner_logo' => ['model' => Partner::class, 'column' => 'logo_path', 'label' => 'логотип партнёра'],
        'library_cover' => ['model' => MedicalLibrary::class, 'column' => 'image_path', 'label' => 'обложка материала'],
    ];

    public function find(string $target, int $id): Model
    {
        return self::TARGETS[$target]['model']::query()->find($id)
            ?? throw new ToolException("Не найдено: {$id} ({$target})");
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public function saveImage(string $target, Model $model, array $source): string
    {
        $file = MediaSource::fetch($source, MediaSource::MAX_IMAGE);

        try {
            $webp = (string) (new ImageManager(new Driver))->read($file['bytes'])->toWebp(90);
        } catch (Throwable) {
            throw new ToolException('Файл не похож на изображение (нужны jpeg, png, gif или webp).');
        }

        $this->removeImage($target, $model);

        $dir = match ($target) {
            'event_cover' => "events/{$model->id}",
            'speaker_photo' => "speakers/{$model->id}",
            'partner_logo' => "partners/{$model->id}",
            'library_cover' => "medical-library/{$model->id}/images",
        };
        $name = ($target === 'speaker_photo' ? 'photo_' : '').time().'.webp';
        $path = "{$dir}/{$name}";
        Storage::disk('public')->put($path, $webp);

        $column = self::TARGETS[$target]['column'];
        $model->{$column} = in_array($target, ['event_cover', 'speaker_photo'], true) ? '/storage/'.$path : $path;
        $model->save();

        return $model->{$column};
    }

    public function removeImage(string $target, Model $model): void
    {
        $column = self::TARGETS[$target]['column'];
        $old = $model->{$column};

        if ($old && ! str_starts_with($old, 'http')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $old));
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public function saveLibraryFile(MedicalLibrary $item, array $source): string
    {
        $file = MediaSource::fetch($source, MediaSource::MAX_FILE);
        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));

        if (! in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'], true)) {
            throw new ToolException('Документ: pdf, doc, docx, xls, xlsx, ppt, pptx или txt. Укажите filename с расширением.');
        }

        if ($item->file_path && $item->file_path !== 'placeholder') {
            Storage::disk('public')->delete($item->file_path);
        }

        $name = (Str::slug(pathinfo($file['filename'], PATHINFO_FILENAME)) ?: 'file').'_'.time().'.'.$ext;
        $path = "medical-library/{$item->id}/files/{$name}";
        Storage::disk('public')->put($path, $file['bytes']);

        $item->file_path = $path;
        $item->save();

        return $path;
    }
}
