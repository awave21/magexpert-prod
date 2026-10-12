<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends SenderModel
{
    protected $table = 'sender_users';

    /**
     * owner — владелец (один на организацию): всё, что может admin, плюс название организации и передача владения;
     * admin — всё, включая домены, ключи, вебхуки и команду; editor — письма, базы и рассылки;
     * designer — только письма в «Контенте»; viewer — только просмотр.
     */
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_DESIGNER = 'designer';

    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_DESIGNER, self::ROLE_VIEWER];

    /**
     * Роли, которые можно выдать через команду. Владельцем становятся только передачей владения.
     */
    public const ASSIGNABLE_ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_DESIGNER, self::ROLE_VIEWER];

    public const ADMIN_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN];

    protected $fillable = ['organization_id', 'name', 'email', 'role', 'password', 'password_set', 'is_active'];

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_set' => 'boolean',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Пароль задан самим пользователем (false — после регистрации через Яндекс или ВКонтакте со случайным паролем).
     * Новая запись без значения берёт значение колонки по умолчанию — true.
     */
    public function hasPassword(): bool
    {
        return $this->password_set ?? true;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Владелец тоже администратор.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role ?? self::ROLE_ADMIN, self::ADMIN_ROLES, true);
    }

    /**
     * Базы, рассылки и всё остальное, кроме настроек администратора.
     */
    public function canEdit(): bool
    {
        return in_array($this->role ?? self::ROLE_ADMIN, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    /**
     * Письма, папки, картинки и переменные в «Контенте»: это может и дизайнер.
     */
    public function canEditContent(): bool
    {
        return $this->canEdit() || $this->role === self::ROLE_DESIGNER;
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
