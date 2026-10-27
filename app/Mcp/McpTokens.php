<?php

namespace App\Mcp;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Личные ключи MCP: в базе только SHA-256, сам ключ показывается один раз.
 */
class McpTokens
{
    public const PREFIX = 'mxe_';

    public const MAX_ACTIVE = 10;

    /**
     * Роли, которым можно пользоваться MCP.
     */
    public const ROLES = ['admin', 'manager', 'editor'];

    /**
     * @return array{token: McpToken, plain: string}
     */
    public function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = McpToken::query()->create([
            'user_id' => $user->id,
            'name' => Str::limit(trim($name) ?: 'ИИ-ассистент', 100, ''),
            'token_hash' => hash('sha256', $plain),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public function authenticate(string $plain): ?McpToken
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = McpToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();

        if ($token?->user === null || ! $token->user->hasAnyRole(self::ROLES)) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $token;
    }

    public function revoke(McpToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
