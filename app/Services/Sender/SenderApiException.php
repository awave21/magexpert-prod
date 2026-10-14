<?php

namespace App\Services\Sender;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Ошибка обращения к публичному API Sender (контракт: docs/sender-api.openapi.yaml).
 *
 * status — HTTP-код ответа; 0, если ответа не было (нет соединения, API не настроен).
 * retryable — имеет ли смысл повторить запрос позже (нет соединения, 429, 5xx).
 */
class SenderApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  ошибки проверки данных (ответ 422)
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(0, 'API Sender не настроен: укажите SENDER_API_URL и SENDER_API_KEY');
    }

    public static function connection(Throwable $previous): self
    {
        return new self(0, 'Нет соединения с Sender: '.$previous->getMessage(), true, [], $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $errors = (array) ($response->json('errors') ?? []);
        $firstError = collect($errors)->flatten()->first();
        $serverMessage = (string) ($response->json('message') ?? $response->json('error') ?? '');

        $message = match (true) {
            $status === 401 => 'Sender не принял API-ключ (401): проверьте SENDER_API_KEY',
            $status === 403 => 'У API-ключа нет доступа к этому действию в Sender (403)',
            $status === 404 => 'В Sender не найдено: база или контакт (404)',
            $status === 409 => 'Sender отказал (409)'.($serverMessage !== '' ? ': '.$serverMessage : '').'. Укажите другое название в SENDER_USERS_LIST_NAME',
            $status === 422 => 'Sender не принял данные (422)'.($firstError ? ': '.$firstError : ''),
            $status === 429 => 'Слишком много запросов к Sender (429), повторим позже',
            $status >= 500 => "Ошибка на стороне Sender ({$status})",
            default => "Sender ответил ошибкой ({$status})".($serverMessage !== '' ? ': '.$serverMessage : ''),
        };

        return new self($status, $message, $status === 429 || $status >= 500, $errors);
    }
}
