<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'url',
        'read',
        'read_at'
    ];

    protected $casts = [
        'data' => 'array',
        'read' => 'boolean',
        'read_at' => 'datetime'
    ];

    /**
     * Отношение к пользователю
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Отметить уведомление как прочитанное
     */
    public function markAsRead(): bool
    {
        if (!$this->read) {
            $this->update([
                'read' => true,
                'read_at' => now()
            ]);
            return true;
        }
        return false;
    }

    /**
     * Отметить уведомление как непрочитанное
     */
    public function markAsUnread(): bool
    {
        if ($this->read) {
            $this->update([
                'read' => false,
                'read_at' => null
            ]);
            return true;
        }
        return false;
    }

    /**
     * Создать уведомление
     */
    public static function createNotification(array $data): self
    {
        return self::create([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'data' => $data['data'] ?? null,
            'url' => $data['url'] ?? null,
            'read' => $data['read'] ?? false
        ]);
    }
}
