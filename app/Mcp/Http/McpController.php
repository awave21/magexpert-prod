<?php

namespace App\Mcp\Http;

use App\Mcp\JsonRpcException;
use App\Mcp\McpContext;
use App\Mcp\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use JsonException;

/**
 * Streamable HTTP без состояния: POST с сообщением JSON-RPC, ответ application/json.
 */
class McpController extends Controller
{
    private const MAX_BODY = 2 * 1024 * 1024;

    public function __construct(private readonly Server $server) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY) {
            return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Слишком большой запрос'), 413);
        }

        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json($this->server->error(null, JsonRpcException::PARSE_ERROR, 'Не удалось разобрать JSON'), 400);
        }

        $token = $request->attributes->get('mcp_token');
        $ctx = new McpContext($token->user, $token, $request->ip());

        if (is_array($payload) && array_is_list($payload)) {
            if ($payload === []) {
                return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Пустая пачка сообщений'), 400);
            }

            $responses = array_values(array_filter(array_map(fn (mixed $m): ?array => $this->server->handle($m, $ctx), $payload)));

            return $responses === [] ? response('', 202) : $this->json($responses);
        }

        $response = $this->server->handle($payload, $ctx);

        return $response === null ? response('', 202) : $this->json($response);
    }

    public function methodNotAllowed(): JsonResponse
    {
        return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Используйте POST'), 405)->header('Allow', 'POST');
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
