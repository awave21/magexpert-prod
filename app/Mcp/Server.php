<?php

namespace App\Mcp;

use App\Mcp\Support\ArgumentValidator;
use App\Mcp\Support\Entities;
use App\Mcp\Tools\EntityTool;
use App\Mcp\Tools\EventStateTool;
use App\Mcp\Tools\MediaTool;
use App\Mcp\Tools\SetEventSpeakersTool;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP-сервер MagExpert: JSON-RPC 2.0 без состояния. Инструменты — команды над данными сайта; никаких правил «как писать» здесь нет.
 */
class Server
{
    public const VERSION = '1.0.0';

    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * @var array<string, Tool>|null
     */
    private ?array $tools = null;

    public function __construct(private readonly ArgumentValidator $validator) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, McpContext $ctx): ?array
    {
        if (! is_array($message) || array_is_list($message)) {
            return $this->error(null, JsonRpcException::INVALID_REQUEST, 'Неверный запрос JSON-RPC');
        }

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

        if (! $hasId) {
            return null;
        }

        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->dispatch($message['method'], $params, $ctx)];
        } catch (JsonRpcException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->data());
        } catch (Throwable $e) {
            Log::error('MagExpert MCP: внутренняя ошибка', ['method' => $message['method'], 'exception' => $e]);

            return $this->error($id, JsonRpcException::INTERNAL_ERROR, 'Внутренняя ошибка сервера.');
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
            'tools/list' => ['tools' => array_map(fn (Tool $t): array => $this->describe($t), array_values($this->tools()))],
            'tools/call' => $this->callTool($params, $ctx),
            'resources/list' => ['resources' => []],
            'resources/templates/list' => ['resourceTemplates' => []],
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
            'protocolVersion' => is_string($requested) && in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'magexpert', 'title' => 'MagExpert — сайт', 'version' => self::VERSION],
            'instructions' => 'Сервер управляет данными сайта MagExpert: мероприятия, спикеры, категории, партнёры, материалы библиотеки. '
                .'Можно создавать, менять, публиковать и скрывать; удаление — только с confirm: true и только администратору и менеджеру. '
                .'Картинки (обложки, фото, логотипы) и документы библиотеки загружаются по ссылке https или base64. Все изменения пишутся в журнал.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpContext $ctx): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new JsonRpcException('Не указан параметр name', JsonRpcException::INVALID_PARAMS);
        }
        $tool = $this->tools()[$name] ?? throw new JsonRpcException("Инструмент «{$name}» не найден", JsonRpcException::INVALID_PARAMS);
        $arguments = $params['arguments'] ?? [];

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new JsonRpcException('Параметр arguments должен быть объектом', JsonRpcException::INVALID_PARAMS);
        }

        $errors = $this->validator->validate($arguments, $tool->schema());
        if ($errors !== []) {
            throw new JsonRpcException('Неверные аргументы: '.implode('; ', $errors), JsonRpcException::INVALID_PARAMS, ['errors' => $errors]);
        }

        try {
            $result = $tool->handle($arguments, $ctx);
        } catch (ToolException $e) {
            return $this->toolError($e->getMessage(), $e->errors());
        } catch (Throwable $e) {
            Log::error('MagExpert MCP: ошибка инструмента', ['tool' => $name, 'exception' => $e]);

            return $this->toolError('Не получилось выполнить действие из-за внутренней ошибки.');
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
        $text = $errors === [] ? $message : $message."\n".$this->json(['errors' => $errors]);

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
            $list = [];
            foreach (Entities::KEYS as $entity) {
                foreach (EntityTool::ACTIONS as $action) {
                    $list[] = new EntityTool($entity, $action);
                }
                if ($entity === 'event') {
                    array_push($list, new EventStateTool(true), new EventStateTool(false), new SetEventSpeakersTool);
                }
            }
            array_push($list, new MediaTool('set_image'), new MediaTool('remove_image'), new MediaTool('set_library_file'));
            $this->tools = [];
            foreach ($list as $tool) {
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
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
