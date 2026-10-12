<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Фрагмент документа библиотеки для поиска ИИ-помощника: текст раздела или таблица с номерами страниц и вектором.
 */
class LibraryChunk extends Model
{
    /** @use HasFactory<\Database\Factories\LibraryChunkFactory> */
    use HasFactory;

    protected $fillable = [
        'medical_library_id',
        'position',
        'type',
        'page_start',
        'page_end',
        'headings',
        'content',
        'embed_text',
        'content_sha256',
        'tokens',
        'embedding',
    ];

    protected $hidden = ['embedding', 'search'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MedicalLibrary, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MedicalLibrary::class, 'medical_library_id');
    }
}
