<?php

namespace App\Sender\Mcp;

use App\Sender\Models\McpToken;
use App\Sender\Models\User;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Str;

/**
 * Выдача и проверка личных ключей MCP. В базе хранится только SHA-256 ключа, сам ключ показывается один раз.
 */
class McpTokens
{
    public const PREFIX = 'mxm_';

    /**
     * Сколько действующих ключей может быть у одного пользователя.
     */
    public const MAX_ACTIVE = 20;

    /**
     * @return array{token: McpToken, plain: string}
     */
    public function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = McpToken::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => Str::limit(trim($name) ?: 'ИИ-ассистент', 100, ''),
            'token_hash' => $this->hash($plain),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * Действующий ключ активного пользователя активной организации или null.
     */
    public function authenticate(string $plain): ?McpToken
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = McpToken::query()
            ->with('user.organization')
            ->where('token_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->first();

        $user = $token?->user;

        if ($user === null || ! $user->is_active || ! $user->organization?->is_active
            || (int) $user->organization_id !== (int) $token->organization_id) {
            return null;
        }

        // отметку об использовании пишем не чаще раза в минуту, чтобы не нагружать базу на каждый вызов
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $token;
    }

    public function revoke(McpToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Адрес MCP-сервера, который вставляют в настройки ассистента.
     */
    public function url(): string
    {
        return SenderUi::host() !== null ? SenderUi::url('mcp') : url('/api/sender/v1/mcp');
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
