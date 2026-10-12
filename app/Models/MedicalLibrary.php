<?php

namespace App\Models;

use App\Jobs\IndexLibraryDocument;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class MedicalLibrary extends Model
{
    use HasFactory;

    /**
     * Атрибуты, которые можно массово назначать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'file_path',
        'image_path',
        'publication_date',
        'language',
    ];

    /**
     * Атрибуты, которые должны быть приведены к типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'publication_date' => 'date',
    ];

    /**
     * Атрибуты, которые должны быть добавлены к массивам модели.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'file_url',
        'image_url',
    ];

    /**
     * Новый или заменённый PDF сразу попадает в поиск ИИ-помощника (когда помощник работает по документам).
     */
    protected static function booted(): void
    {
        static::saved(function (MedicalLibrary $document): void {
            $fileChanged = $document->wasRecentlyCreated || $document->wasChanged('file_path');

            if ($fileChanged && $document->file_path && config('services.assistant.driver') === 'rag') {
                IndexLibraryDocument::dispatch($document->id);
            }
        });
    }

    /**
     * Получить полный URL к файлу.
     *
     * @return string|null
     */
    public function getFileUrlAttribute()
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }

    /**
     * Получить полный URL к изображению.
     *
     * @return string|null
     */
    public function getImageUrlAttribute()
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    /**
     * Фрагменты документа в поисковом индексе ИИ-помощника.
     *
     * @return HasMany<LibraryChunk, $this>
     */
    public function libraryChunks(): HasMany
    {
        return $this->hasMany(LibraryChunk::class);
    }

    /**
     * @return HasOne<LibraryIndex, $this>
     */
    public function libraryIndex(): HasOne
    {
        return $this->hasOne(LibraryIndex::class);
    }
}
