<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Адрес отправителя на домене организации: с него и от этого имени уходят письма шаблонов.
 */
class SenderAddress extends SenderModel
{
    protected $table = 'sender_addresses';

    protected $fillable = ['organization_id', 'domain_id', 'email', 'name'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
