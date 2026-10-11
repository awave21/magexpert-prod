<?php

namespace App\Sender\Http\Resources;

use App\Sender\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Sender\Models\Domain
 */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'status' => $this->status,
            'dkim_selector' => $this->dkim_selector,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'dns_records' => $this->when(
                $request->routeIs('sender.admin.domains.show', 'sender.admin.domains.store', 'sender.admin.domains.verify'),
                fn (): array => app(DomainService::class)->dnsRecords($this->resource),
            ),
        ];
    }
}
