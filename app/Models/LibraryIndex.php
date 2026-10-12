<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Состояние индексации документа библиотеки: чем и из какой версии файла получены фрагменты.
 */
class LibraryIndex extends Model
{
    protected $table = 'library_indexes';

    protected $fillable = [
        'medical_library_id',
        'file_sha256',
        'parser',
        'parser_version',
        'embedding_model',
        'language',
        'pages',
        'chunks_count',
        'indexed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'indexed_at' => 'datetime',
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
