<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * База подписчиков.
 */
class ContactList extends SenderModel
{
    protected $table = 'sender_lists';

    protected $fillable = ['organization_id', 'name', 'description'];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'list_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'list_id');
    }
}
