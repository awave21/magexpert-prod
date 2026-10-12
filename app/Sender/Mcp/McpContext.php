<?php

namespace App\Sender\Mcp;

use App\Sender\Models\AuditLog;
use App\Sender\Models\McpToken;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use App\Sender\Models\User;
use Illuminate\Support\Str;

/**
 * Кто вызывает инструмент: пользователь ключа, его организация и роль. Все запросы — только в пределах организации.
 */
class McpContext
{
    public readonly Permissions $permissions;

    public function __construct(
        public readonly User $user,
        public readonly Organization $organization,
        public readonly ?McpToken $token = null,
        public readonly ?string $ip = null,
    ) {
        $this->permissions = new Permissions($user->role);
    }

    /**
     * Проверка перед любым изменением писем.
     */
    public function requireContentEditing(): void
    {
        if (! $this->permissions->canEditContent()) {
            throw new ToolException('Ваша роль не позволяет менять письма');
        }
    }

    /**
     * Шаблон организации по ID или идентификатору (slug).
     */
    public function template(int|string|null $reference): Template
    {
        $reference = is_string($reference) ? trim($reference) : $reference;

        if ($reference === null || $reference === '') {
            throw new ToolException('Укажите письмо: его ID или идентификатор (slug)');
        }

        $query = $this->organization->templates()->with('folder');
        $template = is_int($reference) || ctype_digit((string) $reference)
            ? (clone $query)->whereKey((int) $reference)->first() ?? (clone $query)->where('slug', (string) $reference)->first()
            : $query->where('slug', $reference)->first();

        if ($template === null) {
            throw new ToolException("Письмо «{$reference}» не найдено в вашей организации");
        }

        return $template;
    }

    /**
     * Запись в журнал действий: автор — владелец ключа.
     */
    public function audit(string $action, ?string $subject): void
    {
        AuditLog::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'action' => $action,
            'subject' => $subject !== null ? Str::limit($subject, 195) : null,
            'ip' => $this->ip,
        ]);
    }
}
