<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends SenderModel
{
    protected $table = 'sender_users';

    /**
     * admin — всё, включая домены, ключи, вебхуки и команду; editor — письма, базы и рассылки; viewer — только просмотр.
     */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER];

    protected $fillable = ['organization_id', 'name', 'email', 'role', 'password', 'is_active'];

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

    public function isAdmin(): bool
    {
        return ($this->role ?? self::ROLE_ADMIN) === self::ROLE_ADMIN;
    }

    public function canEdit(): bool
    {
        return in_array($this->role ?? self::ROLE_ADMIN, [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class, 'user_id');
    }
}
