<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends SenderModel
{
    protected $table = 'sender_organizations';

    protected $fillable = ['name', 'slug', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'organization_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'organization_id');
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'organization_id');
    }
}
