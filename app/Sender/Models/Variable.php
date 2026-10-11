<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variable extends SenderModel
{
    protected $table = 'sender_variables';

    protected $fillable = ['organization_id', 'key', 'label', 'default_value'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
