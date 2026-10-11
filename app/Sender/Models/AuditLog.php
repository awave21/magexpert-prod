<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Действие пользователя в админке Sender.
 */
class AuditLog extends SenderModel
{
    public const UPDATED_AT = null;

    protected $table = 'sender_audit_logs';

    protected $fillable = ['organization_id', 'user_id', 'action', 'subject', 'ip'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
