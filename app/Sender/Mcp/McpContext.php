<?php

namespace App\Sender\Mcp;

use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\ContactList;
use App\Sender\Models\McpToken;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use App\Sender\Models\User;
use Illuminate\Support\Facades\RateLimiter;
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
     * Проверка перед любым изменением баз, подписчиков и рассылок.
     */
    public function requireDataEditing(): void
    {
        if (! $this->permissions->canEditData()) {
            throw new ToolException('Ваша роль не позволяет менять базы, подписчиков и рассылки');
        }
    }

    /**
     * Тестовые письма через ассистента (письма и рассылки вместе): не больше $perHour в час на пользователя.
     */
    public function throttleTestLetters(int $perHour): void
    {
        $key = 'sender-mcp-test:'.$this->user->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw new ToolException("Слишком много тестовых писем: не больше {$perHour} в час. Попробуйте через {$minutes} мин.");
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * База подписчиков организации по ID или названию (без учёта регистра).
     */
    public function list(int|string|null $reference): ContactList
    {
        $reference = is_string($reference) ? trim($reference) : $reference;

        if ($reference === null || $reference === '') {
            throw new ToolException('Укажите базу: её ID или название');
        }

        $byId = is_int($reference) || ctype_digit((string) $reference) ? $this->organization->lists()->find((int) $reference) : null;
        $list = $byId ?? $this->organization->lists()->get()
            ->first(fn (ContactList $l): bool => mb_strtolower(trim($l->name)) === mb_strtolower((string) $reference));

        if ($list === null) {
            throw new ToolException("База «{$reference}» не найдена в вашей организации. Список баз — list_lists.");
        }

        return $list;
    }

    public function campaign(int $id): Campaign
    {
        $campaign = $this->organization->campaigns()->with(['template', 'list'])->find($id);

        if ($campaign === null) {
            throw new ToolException("Рассылка {$id} не найдена в вашей организации. Список рассылок — list_campaigns.");
        }

        return $campaign;
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
