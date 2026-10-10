<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка аккаунта сайта ко входу через Яндекс ID или VK ID.
 */
class SocialAccount extends Model
{
    public const PROVIDERS = ['yandex', 'vkid'];

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
