<?php

namespace App\Sender\Mcp;

/**
 * Инструмент MCP: имя, описание для ассистента, JSON Schema аргументов, подсказки о поведении и обработчик.
 */
interface Tool
{
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * JSON Schema аргументов (type: object).
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @return array{readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}
     */
    public function annotations(): array;

    /**
     * Результат — объект, он уходит ассистенту как structuredContent и как текст JSON.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ToolException
     */
    public function handle(array $args, McpContext $ctx): array;
}
