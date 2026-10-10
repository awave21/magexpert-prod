<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение команды в колокольчик кабинета: кому, что и сколько человек получили.
 */
class CabinetBroadcast extends Model
{
    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_EVENT = 'event';

    public const AUDIENCE_USER = 'user';

    protected $fillable = [
        'title',
        'message',
        'url',
        'audience',
        'event_id',
        'recipients_count',
        'sent_by',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
