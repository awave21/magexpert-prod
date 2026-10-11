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
            'from_name' => $this->from_name,
            'reply_to' => $this->reply_to,
            'subject' => $this->subject,
            'template' => $this->template?->slug,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'clicked_at' => $this->clicked_at?->toIso8601String(),
            'opens_count' => $this->opens_count,
            'clicks_count' => $this->clicks_count,
            'tracked' => $this->tracked,
            'events' => $this->when($request->routeIs('sender.admin.messages.show'), fn () => $this->events()->latest('id')->limit(50)->get()->map(fn ($e): array => [
                'type' => $e->type,
                'detail' => $e->detail,
                'is_auto' => $e->is_auto,
                'created_at' => $e->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'variables' => $this->when($request->routeIs('sender.admin.messages.show'), $this->data),
        ];
    }
}
