<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends SenderModel
{
    protected $table = 'sender_templates';

    protected $fillable = [
        'organization_id',
        'folder_id',
        'slug',
        'name',
        'subject',
        'body_html',
        'body_text',
        'editor',
        'design',
    ];

    public const EDITOR_HTML = 'html';

    public const EDITOR_BLOCKS = 'blocks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['design' => 'array'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(TemplateFolder::class, 'folder_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
