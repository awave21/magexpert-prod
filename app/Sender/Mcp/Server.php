<?php

namespace App\Sender\Mcp;

use App\Sender\Mcp\Support\ArgumentValidator;
use App\Sender\Mcp\Tools\CheckTemplateTool;
use App\Sender\Mcp\Tools\CreateTemplateTool;
use App\Sender\Mcp\Tools\GetBlockSchemaTool;
use App\Sender\Mcp\Tools\GetBrandTool;
use App\Sender\Mcp\Tools\GetTemplateTool;
use App\Sender\Mcp\Tools\ListFoldersTool;
use App\Sender\Mcp\Tools\ListTemplatesTool;
use App\Sender\Mcp\Tools\ListVariablesTool;
use App\Sender\Mcp\Tools\RenderTemplateTool;
use App\Sender\Mcp\Tools\SendTestTool;
use App\Sender\Mcp\Tools\UpdateTemplateTool;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP-сервер Sender (уровень 1 — письма): разбирает сообщения JSON-RPC 2.0 и вызывает инструменты, ресурсы и сценарии.
 * Сервер без состояния: каждый HTTP-запрос самодостаточен, сессий и SSE нет.
 */
class Server
{
    public const VERSION = '1.0.0';

    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const DEFAULT_PROTOCOL = '2025-03-26';

    /**
     * @var list<class-string<Tool>>
     */
    public const TOOLS = [
        GetBrandTool::class,
        GetBlockSchemaTool::class,
        ListTemplatesTool::class,
        GetTemplateTool::class,
        CreateTemplateTool::class,
        UpdateTemplateTool::class,
        RenderTemplateTool::class,
        CheckTemplateTool::class,
        SendTestTool::class,
        ListFoldersTool::class,
        ListVariablesTool::class,
    ];

    /**
     * @var array<string, Tool>|null
     */
    private ?array $tools = null;

    public function __construct(
        private readonly Container $container,
        private readonly Resources $resources,
        private readonly Prompts $prompts,
        private readonly ArgumentValidator $validator,
    ) {}

    /**
     * Обрабатывает одно сообщение. Для уведомлений (без id) и ответов клиента возвращает null.
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, McpContext $ctx): ?array
    {
        if (! is_array($message) || array_is_list($message)) {
            return $this->error(null, JsonRpcException::INVALID_REQUEST, 'Неверный запрос JSON-RPC');
        }

        // ответ клиента на запрос сервера (у нас таких нет) — просто принимаем
        if (! isset($message['method']) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            return null;
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)
            || ($hasId && ! is_string($id) && ! is_int($id))) {
            return $this->error(is_string($id) || is_int($id) ? $id : null, JsonRpcException::INVALID_REQUEST, 'Неверный запрос JSON-RPC');
        }

        $params = $message['params'] ?? [];

        if (! is_array($params) || ($params !== [] && array_is_list($params))) {
            return $hasId ? $this->error($id, JsonRpcException::INVALID_PARAMS, 'params должен быть объектом') : null;
        }

        // уведомления (notifications/initialized, notifications/cancelled и т. п.) ответа не требуют
        if (! $hasId) {
            return null;
        }

        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->dispatch($message['method'], $params, $ctx)];
        } catch (JsonRpcException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->data());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|object
     */
    private function dispatch(string $method, array $params, McpContext $ctx): array|object
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => (object) [],
            'tools/list' => ['tools' => array_map(fn (Tool $tool): array => $this->describe($tool), array_values($this->tools()))],
            'tools/call' => $this->callTool($params, $ctx),
            'resources/list' => ['resources' => $this->resources->list()],
            'resources/templates/list' => ['resourceTemplates' => []],
            'resources/read' => ['contents' => [$this->resources->read($this->stringParam($params, 'uri'), $ctx)]],
            'prompts/list' => ['prompts' => $this->prompts->list()],
            'prompts/get' => $this->prompts->get($this->stringParam($params, 'name'), $this->objectParam($params, 'arguments')),
            default => throw new JsonRpcException("Метод «{$method}» не поддерживается", JsonRpcException::METHOD_NOT_FOUND),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => is_string($requested) && in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::DEFAULT_PROTOCOL,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => (object) [],
                'prompts' => (object) [],
            ],
            'serverInfo' => ['name' => 'sender', 'title' => 'Sender — письма', 'version' => self::VERSION],
            'instructions' => 'Sender — сервис email-рассылок MagExpert. Через этот сервер можно работать с письмами (шаблонами) своей организации: '
                .'смотреть список и содержимое, создавать и менять письма из блоков визуального редактора или из HTML, собирать письмо с тестовыми данными, '
                .'проверять его перед отправкой и отправлять тест себе на почту. Удалять письма и запускать рассылки нельзя. '
                .'Перед созданием письма вызовите get_brand и get_block_schema, после — check_template и send_test. '
                .'Права зависят от роли владельца ключа: с ролью «только просмотр» письма можно только читать.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpContext $ctx): array
    {
        $name = $this->stringParam($params, 'name');
        $tool = $this->tools()[$name] ?? throw new JsonRpcException("Инструмент «{$name}» не найден", JsonRpcException::INVALID_PARAMS);
        $arguments = $this->objectParam($params, 'arguments');

        $errors = $this->validator->validate($arguments, $tool->schema());

        if ($errors !== []) {
            throw new JsonRpcException('Неверные аргументы: '.implode('; ', $errors), JsonRpcException::INVALID_PARAMS, ['errors' => $errors]);
        }

        try {
            $result = $tool->handle($arguments, $ctx);
        } catch (ToolException $e) {
            return $this->toolError($e->getMessage(), $e->errors());
        } catch (Throwable $e) {
            Log::error('Sender MCP: ошибка инструмента', ['tool' => $name, 'user_id' => $ctx->user->id, 'exception' => $e]);

            return $this->toolError('Не получилось выполнить действие из-за внутренней ошибки. Попробуйте ещё раз или сообщите администратору.');
        }

        return [
            'content' => [['type' => 'text', 'text' => $this->json($result)]],
            'structuredContent' => (object) $result,
            'isError' => false,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function toolError(string $message, array $errors = []): array
    {
        $text = $message;

        if ($errors !== []) {
            $text .= "\n".$this->json(['errors' => $errors]);
        }

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Tool $tool): array
    {
        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->schema(),
            'annotations' => array_merge(['title' => $tool->title()], $tool->annotations()),
        ];
    }

    /**
     * @return array<string, Tool>
     */
    public function tools(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach (self::TOOLS as $class) {
                $tool = $this->container->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new JsonRpcException("Не указан параметр {$key}", JsonRpcException::INVALID_PARAMS);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function objectParam(array $params, string $key): array
    {
        $value = $params[$key] ?? [];

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new JsonRpcException("Параметр {$key} должен быть объектом", JsonRpcException::INVALID_PARAMS);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    public function error(int|string|null $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
