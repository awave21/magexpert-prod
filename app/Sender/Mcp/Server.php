<?php

namespace App\Sender\Mcp;

use App\Sender\Mcp\Support\ArgumentValidator;
use App\Sender\Mcp\Tools\CancelCampaignTool;
use App\Sender\Mcp\Tools\CheckTemplateTool;
use App\Sender\Mcp\Tools\CountSegmentTool;
use App\Sender\Mcp\Tools\CreateCampaignTool;
use App\Sender\Mcp\Tools\CreateListTool;
use App\Sender\Mcp\Tools\CreateTemplateTool;
use App\Sender\Mcp\Tools\GetBlockSchemaTool;
use App\Sender\Mcp\Tools\GetBrandTool;
use App\Sender\Mcp\Tools\GetCampaignTool;
use App\Sender\Mcp\Tools\GetTemplateTool;
use App\Sender\Mcp\Tools\ListCampaignsTool;
use App\Sender\Mcp\Tools\ListContactsTool;
use App\Sender\Mcp\Tools\ListFieldsTool;
use App\Sender\Mcp\Tools\ListFoldersTool;
use App\Sender\Mcp\Tools\ListListsTool;
use App\Sender\Mcp\Tools\ListTemplatesTool;
use App\Sender\Mcp\Tools\ListVariablesTool;
use App\Sender\Mcp\Tools\RenderTemplateTool;
use App\Sender\Mcp\Tools\ScheduleCampaignTool;
use App\Sender\Mcp\Tools\SendCampaignTestTool;
use App\Sender\Mcp\Tools\SendTestTool;
use App\Sender\Mcp\Tools\UpdateCampaignTool;
use App\Sender\Mcp\Tools\UpdateListTool;
use App\Sender\Mcp\Tools\UpdateTemplateTool;
use App\Sender\Mcp\Tools\UpsertContactsTool;
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
        // письма
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
        // базы, подписчики и сегменты
        ListListsTool::class,
        CreateListTool::class,
        UpdateListTool::class,
        ListContactsTool::class,
        UpsertContactsTool::class,
        ListFieldsTool::class,
        CountSegmentTool::class,
        // рассылки
        ListCampaignsTool::class,
        GetCampaignTool::class,
        CreateCampaignTool::class,
        UpdateCampaignTool::class,
        SendCampaignTestTool::class,
        ScheduleCampaignTool::class,
        CancelCampaignTool::class,
    ];

    /**
     * @var array<string, Tool>|null
     */
    private ?array $tools = null;

    public function __construct(
        private readonly Container $container,
        private readonly Resources $resources,
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
        } catch (Throwable $e) {
            Log::error('Sender MCP: внутренняя ошибка', ['method' => $message['method'], 'user_id' => $ctx->user->id, 'exception' => $e]);

            return $this->error($id, JsonRpcException::INTERNAL_ERROR, 'Внутренняя ошибка сервера. Попробуйте ещё раз или сообщите администратору.');
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
            // неизвестную версию клиента сервер заменяет последней поддерживаемой (спецификация MCP, lifecycle)
            'protocolVersion' => is_string($requested) && in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => (object) [],
            ],
            'serverInfo' => ['name' => 'sender', 'title' => 'Sender — письма', 'version' => self::VERSION],
            'instructions' => 'Sender — сервис email-рассылок. Сервер работает с данными организации владельца ключа: '
                .'письма (шаблоны), базы подписчиков, подписчики, сегменты и рассылки. '
                .'Рассылку можно создать, проверить тестом себе, запланировать на время и отменить; отправить сразу и удалять что-либо через сервер нельзя. '
                .'Права — как у роли владельца ключа в Sender: просмотр — только чтение, дизайнер — письма, редактор и выше — также базы и рассылки.',
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
