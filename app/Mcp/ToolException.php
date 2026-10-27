<?php

namespace App\Mcp;

use RuntimeException;

/**
 * Ошибка выполнения инструмента: ассистент получает её как результат с isError и текстом на русском,
 * чтобы исправить запрос и попробовать снова.
 */
class ToolException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  поле => сообщения
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
