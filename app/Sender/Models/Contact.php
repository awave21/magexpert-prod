<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписчик в базе. Дополнительные колонки из файла хранятся в data и доступны в письме как переменные.
 */
class Contact extends SenderModel
{
    protected $table = 'sender_contacts';

    protected $fillable = ['organization_id', 'list_id', 'email', 'name', 'data', 'unsubscribed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function scopeSubscribed(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }
}
