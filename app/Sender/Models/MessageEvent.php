<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageEvent extends SenderModel
{
    public const TYPE_DELIVERED = 'delivered';

    public const TYPE_DEFERRED = 'deferred';

    public const TYPE_BOUNCED = 'bounced';

    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    public const UPDATED_AT = null;

    protected $table = 'sender_message_events';

    protected $fillable = ['message_id', 'type', 'detail', 'ip', 'user_agent', 'is_auto'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_auto' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
