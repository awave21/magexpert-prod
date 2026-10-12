<?php

namespace App\Sender\Client;

use App\Sender\Contracts\ContactSyncClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Базы контактов Sender через открытый HTTP API (SENDER_API_URL + SENDER_API_KEY), как у внешнего клиента.
 *
 * Запрос повторяется сразу (до 3 попыток) только при обрыве соединения, ответах 5xx и 429;
 * для 429 учитывается Retry-After, если он не больше 30 секунд. Остальные ошибки — SenderApiException.
 */
class HttpContactSyncClient implements ContactSyncClient
{
    private const ATTEMPTS = 3;

    private const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * Указаны ли адрес и ключ API Sender.
     */
    public static function configured(): bool
    {
        return filled(config('sender.client.url')) && filled(config('sender.client.api_key'));
    }

    /**
     * Базовый адрес API. SENDER_API_URL можно указать и как адрес сервера (https://mag-expert.ru),
     * и сразу как адрес API (https://mag-expert.ru/api/sender/v1).
     */
    public static function baseUrl(): string
    {
        $url = rtrim((string) config('sender.client.url'), '/');

        return str_ends_with($url, '/api/sender/v1') ? $url : $url.'/api/sender/v1';
    }

    public function ensureList(string $name, ?string $description = null): int
    {
        $response = $this->send('put', '/lists', array_filter(['name' => $name, 'description' => $description], fn ($value) => $value !== null));

        return (int) $response->json('data.id');
    }

    public function upsertContact(int $listId, string $email, ?string $name, array $data, bool $subscribed): string
    {
        $response = $this->send('put', "/lists/{$listId}/contacts", [
            'email' => mb_strtolower($email),
            'name' => $name,
            'data' => $data,
            'subscribed' => $subscribed,
        ]);

        return (string) $response->json('data.status');
    }

    public function upsertContacts(int $listId, array $contacts): array
    {
        $data = (array) $this->send('post', "/lists/{$listId}/contacts/batch", ['contacts' => array_values($contacts)], 60)->json('data');

        return [
            'created' => (int) ($data['created'] ?? 0),
            'updated' => (int) ($data['updated'] ?? 0),
            'subscribed' => (int) ($data['subscribed'] ?? 0),
            'pending' => (int) ($data['pending'] ?? 0),
            'unsubscribed' => (int) ($data['unsubscribed'] ?? 0),
            'invalid' => array_values((array) ($data['invalid'] ?? [])),
        ];
    }

    public function deleteContact(int $listId, string $email): void
    {
        try {
            $this->send('delete', "/lists/{$listId}/contacts/".rawurlencode(mb_strtolower($email)));
        } catch (SenderApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SenderApiException
     */
    private function send(string $method, string $path, array $payload = [], int $timeout = 15): Response
    {
        try {
            $response = $this->request($timeout)->{$method}(ltrim($path, '/'), $payload);
        } catch (ConnectionException $exception) {
            throw SenderApiException::connection($exception);
        }

        if (! $response->successful()) {
            throw SenderApiException::fromResponse($response);
        }

        return $response;
    }

    private function request(int $timeout): PendingRequest
    {
        if (! self::configured()) {
            throw SenderApiException::notConfigured();
        }

        return Http::baseUrl(self::baseUrl())
            ->withToken((string) config('sender.client.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout($timeout)
            ->retry(
                self::ATTEMPTS,
                fn (int $attempt, ?Throwable $exception = null): int => $this->delayMilliseconds($attempt, $exception),
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
                throw: false,
            );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;

        if ($response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($response);

            return $retryAfter === null || $retryAfter <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return $response->serverError();
    }

    private function delayMilliseconds(int $attempt, ?Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($exception->response);

            if ($retryAfter !== null) {
                return min($retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000;
            }
        }

        return 500 * $attempt;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
