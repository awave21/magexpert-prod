<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рассылка: контент (шаблон) по базе подписчиков.
 */
class Campaign extends SenderModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    protected $table = 'sender_campaigns';

    protected $fillable = ['organization_id', 'name', 'template_id', 'list_id', 'status', 'recipients_count', 'started_at', 'finished_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'campaign_id');
    }
}
