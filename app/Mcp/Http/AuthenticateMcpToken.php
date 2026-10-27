<?php

namespace App\Mcp\Http;

use App\Mcp\JsonRpcException;
use App\Mcp\McpTokens;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход по ключу (Authorization: Bearer mxe_…) и ограничение частоты запросов.
 */
class AuthenticateMcpToken
{
    public const PER_MINUTE = 120;

    public function __construct(private readonly McpTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? $this->tokens->authenticate($plain) : null;

        if ($token === null) {
            return $this->error(401, JsonRpcException::UNAUTHORIZED, $plain
                ? 'Ключ MCP недействителен или отозван.'
                : 'Нужен ключ MCP: заголовок Authorization: Bearer <ключ>.');
        }

        $key = 'mcp:'.$token->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return $this->error(429, JsonRpcException::INTERNAL_ERROR, 'Слишком много запросов. Повторите через '.RateLimiter::availableIn($key).' с.');
        }

        RateLimiter::hit($key, 60);
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    private function error(int $status, int $code, string $message): JsonResponse
    {
        return response()->json(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]],
            $status,
            $status === 401 ? ['WWW-Authenticate' => 'Bearer realm="magexpert"'] : [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
