<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Вопрос к ИИ-помощнику и что он ответил: для раздела «ИИ-помощник» в админке.
 */
class AssistantQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantQuestionFactory> */
    use HasFactory, Prunable;

    protected $fillable = [
        'user_id',
        'question',
        'found',
        'sources',
        'model',
        'input_tokens',
        'output_tokens',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'sources' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LibraryDocumentOpen, $this>
     */
    public function opens(): HasMany
    {
        return $this->hasMany(LibraryDocumentOpen::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths((int) config('services.assistant.history_months')));
    }
}
