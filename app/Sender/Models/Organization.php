<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends SenderModel
{
    protected $table = 'sender_organizations';

    protected $fillable = ['name', 'slug', 'is_active', 'daily_limit'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'daily_limit' => 'integer'];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'organization_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'organization_id');
    }

    public function senderAddresses(): HasMany
    {
        return $this->hasMany(SenderAddress::class, 'organization_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class, 'organization_id');
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class, 'organization_id');
    }

    public function templateFolders(): HasMany
    {
        return $this->hasMany(TemplateFolder::class, 'organization_id');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class, 'organization_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class, 'organization_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    /**
     * Владелец организации: у пользователя роль owner, он один.
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class, 'organization_id')->where('role', User::ROLE_OWNER);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'organization_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'organization_id');
    }

    public function lists(): HasMany
    {
        return $this->hasMany(ContactList::class, 'organization_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'organization_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'organization_id');
    }
}
