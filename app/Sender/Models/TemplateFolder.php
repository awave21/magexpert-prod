<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateFolder extends SenderModel
{
    protected $table = 'sender_template_folders';

    protected $fillable = ['organization_id', 'name'];

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class, 'folder_id');
    }
}
