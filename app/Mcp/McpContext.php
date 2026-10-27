<?php

namespace App\Mcp;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\User;

/**
 * Кто вызывает инструмент: пользователь, его ключ и адрес; здесь же пишется журнал действий.
 */
class McpContext
{
    public function __construct(
        public readonly User $user,
        public readonly McpToken $token,
        public readonly ?string $ip = null,
    ) {}

    /**
     * Удалять могут только администратор и менеджер — как в админке.
     */
    public function canDelete(): bool
    {
        return $this->user->hasAnyRole(['admin', 'manager']);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function audit(string $tool, string $action, string $subjectType, ?int $subjectId, array $details = []): void
    {
        McpAuditLog::query()->create([
            'user_id' => $this->user->id,
            'mcp_token_id' => $this->token->id,
            'tool' => $tool,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => $details ?: null,
            'ip' => $this->ip,
        ]);
    }
}
