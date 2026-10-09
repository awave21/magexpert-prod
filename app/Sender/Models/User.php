<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends SenderModel
{
    protected $table = 'sender_users';

    protected $fillable = ['organization_id', 'name', 'email', 'password', 'is_active'];

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id');
    }
}
