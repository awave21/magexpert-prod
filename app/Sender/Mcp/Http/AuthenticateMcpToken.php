<?php

namespace App\Sender\Mcp\Http;

use App\Sender\Mcp\JsonRpcException;
use App\Sender\Mcp\McpTokens;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход в MCP-сервер по личному ключу (Authorization: Bearer mxm_…) и ограничение частоты запросов на ключ.
 */
class AuthenticateMcpToken
{
    /**
     * Запросов в минуту на один ключ.
     */
    public const PER_MINUTE = 120;

    public function __construct(private readonly McpTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? $this->tokens->authenticate($plain) : null;

        if ($token === null) {
            return $this->error(401, JsonRpcException::UNAUTHORIZED, $plain
                ? 'Ключ MCP недействителен или отозван. Создайте новый в настройках профиля Sender.'
                : 'Нужен ключ MCP: заголовок Authorization: Bearer <ключ>.', [
                    'WWW-Authenticate' => 'Bearer realm="sender"',
                ]);
        }

        $key = 'sender-mcp:'.$token->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $retry = RateLimiter::availableIn($key);

            return $this->error(429, JsonRpcException::INTERNAL_ERROR, "Слишком много запросов. Повторите через {$retry} с.", [
                'Retry-After' => (string) $retry,
            ]);
        }

        RateLimiter::hit($key, 60);

        $request->attributes->set('sender_mcp_token', $token);
        $request->attributes->set('sender_user', $token->user);
        $request->attributes->set('sender_organization', $token->user->organization);

        return $next($request);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(int $status, int $code, string $message, array $headers): JsonResponse
    {
        return response()->json(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]],
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE,
        );
    }
}
