<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * База подписчиков.
 */
class ContactList extends SenderModel
{
    protected $table = 'sender_lists';

    protected $fillable = ['organization_id', 'name', 'description', 'source', 'synced_at', 'require_confirmation'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'require_confirmation' => 'boolean'];
    }

    /**
     * База повторяет подписчиков из приложения: вручную в неё не добавляют.
     */
    public function isSynced(): bool
    {
        return $this->source !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'list_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'list_id');
    }
}
