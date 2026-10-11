<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Адрес приложения, на который Sender отправляет события писем. Тело подписано HMAC-SHA256 секретом вебхука.
 */
class Webhook extends SenderModel
{
    public const EVENT_DELIVERED = 'message.delivered';

    public const EVENT_BOUNCED = 'message.bounced';

    public const EVENT_COMPLAINED = 'message.complained';

    public const EVENT_OPENED = 'message.opened';

    public const EVENT_CLICKED = 'message.clicked';

    public const EVENT_UNSUBSCRIBED = 'recipient.unsubscribed';

    public const EVENTS = [self::EVENT_DELIVERED, self::EVENT_BOUNCED, self::EVENT_COMPLAINED, self::EVENT_OPENED, self::EVENT_CLICKED, self::EVENT_UNSUBSCRIBED];

    protected $table = 'sender_webhooks';

    protected $fillable = ['organization_id', 'url', 'secret', 'events', 'is_active', 'last_status', 'last_error', 'last_sent_at'];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_status' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * Пустой список событий — все события.
     */
    public function wants(string $event): bool
    {
        return empty($this->events) || in_array($event, $this->events, true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
