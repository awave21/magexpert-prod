<?php

namespace App\Sender\Mcp\Http;

use App\Sender\Mcp\JsonRpcException;
use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use JsonException;

/**
 * Транспорт Streamable HTTP без состояния: POST с сообщением (или пачкой сообщений) JSON-RPC, ответ — application/json.
 * Потока SSE нет, поэтому GET и DELETE отвечают 405.
 */
class McpController extends Controller
{
    /**
     * Самое большое сообщение, которое принимаем (письмо с HTML и блоками).
     */
    private const MAX_BODY = 2 * 1024 * 1024;

    public function __construct(private readonly Server $server) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $version = $request->header('MCP-Protocol-Version');

        if ($version !== null && ! in_array($version, Server::PROTOCOL_VERSIONS, true)) {
            return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, "Версия протокола {$version} не поддерживается. Поддерживаются: ".implode(', ', Server::PROTOCOL_VERSIONS)), 400);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY) {
            return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Слишком большой запрос'), 413);
        }

        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json($this->server->error(null, JsonRpcException::PARSE_ERROR, 'Не удалось разобрать JSON'), 400);
        }

        $ctx = new McpContext(
            $request->attributes->get('sender_user'),
            $request->attributes->get('sender_organization'),
            $request->attributes->get('sender_mcp_token'),
            $request->ip(),
        );

        // пачка сообщений (JSON-RPC batch, протокол 2025-03-26)
        if (is_array($payload) && array_is_list($payload)) {
            if ($payload === []) {
                return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Пустая пачка сообщений'), 400);
            }

            $responses = array_values(array_filter(array_map(fn (mixed $message): ?array => $this->server->handle($message, $ctx), $payload)));

            return $responses === [] ? response('', 202) : $this->json($responses);
        }

        $response = $this->server->handle($payload, $ctx);

        if ($response === null) {
            return response('', 202);
        }

        return $this->json($response, isset($response['error']) && $response['error']['code'] === JsonRpcException::INVALID_REQUEST ? 400 : 200);
    }

    /**
     * Сервер не открывает поток событий: только POST.
     */
    public function methodNotAllowed(): JsonResponse
    {
        return $this->json($this->server->error(null, JsonRpcException::INVALID_REQUEST, 'Используйте POST: сервер не поддерживает поток событий (SSE)'), 405)
            ->header('Allow', 'POST');
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
