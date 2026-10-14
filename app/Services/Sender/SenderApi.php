<?php

namespace App\Services\Sender;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Единственный путь сайта в Sender: публичный HTTP API (SENDER_API_URL + SENDER_API_KEY), контракт описан
 * в docs/sender-api.openapi.yaml. Письма, согласие на рассылку, базы и подписчики. Код Sender сайт не вызывает.
 *
 * Запрос повторяется сразу (до 3 попыток) только при обрыве соединения, ответах 5xx и 429;
 * для 429 учитывается Retry-After, если он не больше 30 секунд. Остальные ошибки — SenderApiException.
 */
class SenderApi implements ContactSyncClient, SenderClient, SubscriptionClient
{
    private const ATTEMPTS = 3;

    private const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * Указаны ли адрес и ключ API Sender.
     */
    public static function configured(): bool
    {
        return filled(config('services.sender.url')) && filled(config('services.sender.api_key'));
    }

    /**
     * Базовый адрес API. SENDER_API_URL можно указать и как адрес сервера (https://mag-expert.ru),
     * и сразу как адрес API (https://mag-expert.ru/api/sender/v1).
     */
    public static function baseUrl(): string
    {
        $url = rtrim((string) config('services.sender.url'), '/');

        return str_ends_with($url, '/api/sender/v1') ? $url : $url.'/api/sender/v1';
    }

    /**
     * База по названию: ищется через GET /lists?name=, нет такой, создаётся через POST /lists.
     * Если её только что создал параллельный запрос (409), берётся она.
     */
    public function ensureList(string $name, ?string $description = null): int
    {
        if (($id = $this->findListId($name)) !== null) {
            return $id;
        }

        try {
            $response = $this->call('post', '/lists', array_filter(['name' => $name, 'description' => $description], fn ($value) => $value !== null));

            return (int) $response->json('data.id');
        } catch (SenderApiException $exception) {
            // 409: название уже занято. Это либо параллельно созданная база, либо база, которую Sender собирает сам
            if ($exception->status === 409 && ($id = $this->findListId($name)) !== null) {
                return $id;
            }

            throw $exception;
        }
    }

    private function findListId(string $name): ?int
    {
        $lists = (array) $this->call('get', '/lists', ['name' => $name])->json('data');

        return isset($lists[0]['id']) ? (int) $lists[0]['id'] : null;
    }

    /**
     * Анкета подписчика: базовые поля phone и city из $data уходят отдельными свойствами,
     * остальное дополнительными атрибутами (attributes).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profile(?string $name, array $data): array
    {
        $profile = ['name' => $name];

        foreach (['phone', 'city'] as $base) {
            if (array_key_exists($base, $data)) {
                $profile[$base] = $data[$base];
                unset($data[$base]);
            }
        }

        if ($data !== []) {
            $profile['attributes'] = $data;
        }

        return $profile;
    }

    public function upsertContact(int $listId, string $email, ?string $name, array $data, bool $subscribed, array $tags = []): string
    {
        $response = $this->call('put', "/lists/{$listId}/contacts/".rawurlencode(mb_strtolower($email)), [
            ...$this->profile($name, $data),
            'subscribed' => $subscribed,
            ...($tags === [] ? [] : ['tags' => array_values($tags)]),
        ]);

        return (string) $response->json('data.status');
    }

    public function upsertContacts(int $listId, array $contacts): array
    {
        $rows = array_map(function (array $contact): array {
            $profile = $this->profile($contact['name'] ?? null, (array) ($contact['data'] ?? []));
            unset($contact['name'], $contact['data']);

            return ['email' => mb_strtolower((string) $contact['email']), ...$profile, ...array_diff_key($contact, ['email' => 1])];
        }, array_values($contacts));

        $data = (array) $this->call('post', "/lists/{$listId}/contacts/batch", ['contacts' => $rows], 60)->json('data');

        return [
            'created' => (int) ($data['created'] ?? 0),
            'updated' => (int) ($data['updated'] ?? 0),
            'subscribed' => (int) ($data['subscribed'] ?? 0),
            'pending' => (int) ($data['pending'] ?? 0),
            'unsubscribed' => (int) ($data['unsubscribed'] ?? 0),
            'invalid' => array_values((array) ($data['invalid'] ?? [])),
        ];
    }

    public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array
    {
        try {
            $request = $this->request(15, retry: $idempotencyKey !== null);

            if ($idempotencyKey !== null) {
                $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
            }

            if ($requestId !== null) {
                $request = $request->withHeader('X-Request-Id', $requestId);
            }

            $response = $request->post('messages', ['template' => $template, 'to' => $to, 'data' => $data]);
        } catch (ConnectionException $exception) {
            throw SenderApiException::connection($exception);
        }

        // заблокированное письмо приходит как ресурс с кодом 422: статус blocked и причина в error
        $accepted = $response->successful();
        $blocked = $response->status() === 422 && $response->json('status') === 'blocked';

        return [
            'id' => $response->json('id'),
            'status' => $accepted || $blocked ? (string) $response->json('status', 'queued') : 'failed',
            'error' => $accepted ? null : (string) ($response->json('error') ?? $response->json('detail') ?? $response->json('message') ?? 'HTTP '.$response->status()),
            'http' => $response->status(),
        ];
    }

    public function setSubscription(string $email, bool $subscribed): void
    {
        $this->call('put', '/contacts/'.rawurlencode(mb_strtolower($email)).'/subscription', ['subscribed' => $subscribed]);
    }

    public function deleteContactEverywhere(string $email): void
    {
        $this->call('delete', '/contacts/'.rawurlencode(mb_strtolower($email)));
    }

    public function deleteContact(int $listId, string $email): void
    {
        try {
            $this->call('delete', "/lists/{$listId}/contacts/".rawurlencode(mb_strtolower($email)));
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
    private function call(string $method, string $path, array $payload = [], int $timeout = 15): Response
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

    private function request(int $timeout, bool $retry = true): PendingRequest
    {
        if (! self::configured()) {
            throw SenderApiException::notConfigured();
        }

        $request = Http::baseUrl(self::baseUrl())
            ->withToken((string) config('services.sender.api_key'))
            // сквозной идентификатор: письмо и запрос пользователя связаны в логах обеих сторон
            ->withHeaders(array_filter(['X-Request-Id' => Context::get('request_id')]))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout($timeout);

        // письмо без Idempotency-Key не повторяется: повтор мог бы отправить его дважды
        if (! $retry) {
            return $request;
        }

        return $request->retry(
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
