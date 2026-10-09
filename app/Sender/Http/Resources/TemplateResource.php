<?php

namespace App\Sender\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Sender\Models\Template
 */
class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'folder_id' => $this->folder_id,
            'name' => $this->name,
            'subject' => $this->subject,
            'sender_address_id' => $this->sender_address_id,
            'reply_to' => $this->reply_to,
            'preheader' => $this->preheader,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'editor' => $this->editor ?? 'html',
            'design' => $this->design,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
