<?php

namespace App\Sender\Mcp;

use App\Sender\Mcp\Support\BlockSchema;
use App\Sender\Mcp\Support\Brand;

/**
 * Ресурсы MCP: справочные данные, которые клиент может прочитать и приложить к разговору.
 */
class Resources
{
    public const BRAND = 'sender://brand';

    public const BLOCKS_SCHEMA = 'sender://blocks/schema';

    public function __construct(private readonly Brand $brand) {}

    /**
     * @return list<array{uri: string, name: string, title: string, description: string, mimeType: string}>
     */
    public function list(): array
    {
        return [
            [
                'uri' => self::BRAND,
                'name' => 'brand',
                'title' => 'Фирменный стиль организации',
                'description' => 'Название, отправитель, цвета, шрифты и логотип для писем (то же, что get_brand).',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => self::BLOCKS_SCHEMA,
                'name' => 'blocks-schema',
                'title' => 'Блоки визуального редактора',
                'description' => 'Типы блоков, поля, допустимые значения и пример письма (то же, что get_block_schema).',
                'mimeType' => 'application/json',
            ],
        ];
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     *
     * @throws JsonRpcException
     */
    public function read(string $uri, McpContext $ctx): array
    {
        return match ($uri) {
            self::BRAND => ['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->json($this->brand->for($ctx->organization))],
            self::BLOCKS_SCHEMA => ['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->json(BlockSchema::describe())],
            default => throw new JsonRpcException('Ресурс не найден', JsonRpcException::RESOURCE_NOT_FOUND, ['uri' => $uri]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
