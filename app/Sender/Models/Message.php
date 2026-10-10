<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends SenderModel
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Письмо ушло с нашего сервера: принято Postfix или уже сервером получателя.
     */
    public const SENT_STATUSES = [self::STATUS_SENT, self::STATUS_DELIVERED];

    public const FAILED_STATUSES = [self::STATUS_FAILED, self::STATUS_BOUNCED];

    protected $table = 'sender_messages';

    protected $fillable = [
        'uuid',
        'organization_id',
        'domain_id',
        'template_id',
        'campaign_id',
        'to_email',
        'from_email',
        'from_name',
        'reply_to',
        'subject',
        'status',
        'attempts',
        'error',
        'data',
        'sent_at',
        'smtp_queue_id',
        'tracked',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'opens_count',
        'clicks_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'tracked' => 'boolean',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'opens_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class, 'message_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
