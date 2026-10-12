<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Открытие PDF из библиотеки: из раздела «Библиотека» (source = library) или из ответа ИИ-помощника (assistant).
 */
class LibraryDocumentOpen extends Model
{
    use Prunable;

    public const string FROM_LIBRARY = 'library';

    public const string FROM_ASSISTANT = 'assistant';

    public const UPDATED_AT = null;

    protected $fillable = [
        'medical_library_id',
        'user_id',
        'assistant_question_id',
        'source',
        'page',
    ];

    /**
     * @return BelongsTo<MedicalLibrary, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MedicalLibrary::class, 'medical_library_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths((int) config('services.assistant.history_months')));
    }
}
