<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция просмотра записи: плеер продолжает с неё на любом устройстве.
 */
class VideoProgress extends Model
{
    use HasFactory;

    /** Доля просмотренного, после которой запись считается досмотренной. */
    public const COMPLETED_SHARE = 0.95;

    protected $table = 'video_progress';

    protected $fillable = [
        'user_id',
        'event_id',
        'video_key',
        'position',
        'duration',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Что получает плеер на странице записи: последний ролик и позиции по каждому.
     *
     * @return array{last: ?string, items: array<string, array{position: int, duration: ?int, completed: bool}>}
     */
    public static function forPlayer(User $user, Event $event): array
    {
        $rows = static::query()
            ->where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return [
            'last' => $rows->first()?->video_key,
            'items' => $rows->mapWithKeys(fn (self $row): array => [$row->video_key => [
                'position' => $row->position,
                'duration' => $row->duration,
                'completed' => $row->completed_at !== null,
            ]])->all(),
        ];
    }
}
