<?php

namespace App\Mcp;

use RuntimeException;

/**
 * Ошибка протокола JSON-RPC: неизвестный метод, неверные параметры и т. п.
 */
class JsonRpcException extends RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public const UNAUTHORIZED = -32001;

    public const RESOURCE_NOT_FOUND = -32002;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(string $message, int $code, private readonly ?array $data = null)
    {
        parent::__construct($message, $code);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return $this->data;
    }
}
