<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends SenderModel
{
    protected $table = 'sender_social_accounts';

    protected $fillable = ['user_id', 'provider', 'provider_user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
