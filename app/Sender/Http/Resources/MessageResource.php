<?php

namespace App\Sender\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Sender\Models\Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'to' => $this->to_email,
            'from' => $this->from_email,
            'subject' => $this->subject,
            'template' => $this->template?->slug,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'variables' => $this->when($request->routeIs('sender.admin.messages.show'), $this->data),
        ];
    }
}
