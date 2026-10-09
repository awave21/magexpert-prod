<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends SenderModel
{
    protected $table = 'sender_templates';

    protected $fillable = [
        'organization_id',
        'slug',
        'name',
        'subject',
        'body_html',
        'body_text',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
