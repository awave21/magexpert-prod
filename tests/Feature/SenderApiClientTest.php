<?php

use App\Services\Sender\SenderApi;
use App\Services\Sender\SenderApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.sender.url' => 'https://sender.test', 'services.sender.api_key' => 'test-key']);
});

it('sends a letter by the contract without a sender', function (): void {
    Http::fake(['sender.test/*' => Http::response(['id' => 'uuid-1', 'status' => 'queued', 'error' => null], 202)]);

    $result = app(SenderApi::class)->send('event-registration', 'anna@example.com', ['first_name' => 'Анна']);

    expect($result)->toBe(['id' => 'uuid-1', 'status' => 'queued', 'error' => null, 'http' => 202]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://sender.test/api/sender/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->data() === ['template' => 'event-registration', 'to' => 'anna@example.com', 'data' => ['first_name' => 'Анна']]);
});

it('passes the Idempotency-Key header', function (): void {
    Http::fake(['sender.test/*' => Http::response(['id' => 'uuid-1', 'status' => 'queued'], 202)]);

    app(SenderApi::class)->send('welcome', 'anna@example.com', [], 'reg-63-7');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'reg-63-7'));
});

it('reports a blocked letter with its reason', function (): void {
    Http::fake(['sender.test/*' => Http::response(['id' => 'uuid-2', 'status' => 'blocked', 'error' => 'У шаблона не задан адрес отправителя'], 422)]);

    expect(app(SenderApi::class)->send('welcome', 'anna@example.com'))
        ->toBe(['id' => 'uuid-2', 'status' => 'blocked', 'error' => 'У шаблона не задан адрес отправителя', 'http' => 422]);
});

it('reports an error response as failed with the problem detail', function (): void {
    Http::fake(['sender.test/*' => Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'Шаблон не найден', 'message' => 'Шаблон не найден'], 404, ['Content-Type' => 'application/problem+json'])]);

    expect(app(SenderApi::class)->send('missing', 'anna@example.com'))
        ->toBe(['id' => null, 'status' => 'failed', 'error' => 'Шаблон не найден', 'http' => 404]);
});

it('does not retry a letter sent without an Idempotency-Key', function (): void {
    Http::fake(['sender.test/*' => Http::response(['message' => 'Server Error'], 500)]);

    app(SenderApi::class)->send('welcome', 'anna@example.com');

    Http::assertSentCount(1);
});

it('retries a letter that has an Idempotency-Key after a server error', function (): void {
    Http::fake(['sender.test/*' => Http::sequence()
        ->push(['message' => 'Server Error'], 500)
        ->push(['id' => 'uuid-3', 'status' => 'queued'], 202)]);

    $result = app(SenderApi::class)->send('welcome', 'anna@example.com', [], 'k-1');

    expect($result['status'])->toBe('queued');
    Http::assertSentCount(2);
});

it('sets the subscription and removes an address from every base through the contract', function (): void {
    Http::fake(['sender.test/*' => Http::response(null, 204)]);

    $api = app(SenderApi::class);
    $api->setSubscription('Anna@Example.com', false);
    $api->deleteContactEverywhere('Anna@Example.com');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://sender.test/api/sender/v1/contacts/anna%40example.com/subscription'
        && $request->data() === ['subscribed' => false]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://sender.test/api/sender/v1/contacts/anna%40example.com');
});

it('throws when the API is not configured', function (): void {
    config(['services.sender.url' => null, 'services.sender.api_key' => null]);

    expect(fn () => app(SenderApi::class)->send('welcome', 'anna@example.com'))->toThrow(SenderApiException::class);
});

it('forwards the request id of the current request to Sender', function (): void {
    Http::fake(['sender.test/*' => Http::response(['id' => 'u', 'status' => 'queued'], 202)]);
    Illuminate\Support\Facades\Context::add('request_id', 'site-req-12345678');

    app(SenderApi::class)->setSubscription('anna@example.com', true);
    app(SenderApi::class)->send('welcome', 'anna@example.com', [], null, 'outbox-uuid-12345');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/subscription')
        && $request->hasHeader('X-Request-Id', 'site-req-12345678'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages')
        && $request->hasHeader('X-Request-Id', 'outbox-uuid-12345'));
});

it('finds a list by name and creates it only when it does not exist', function (): void {
    Http::fake([
        'sender.test/api/sender/v1/lists?*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => 9, 'name' => 'Пользователи MagExpert']]]),
        'sender.test/api/sender/v1/lists' => Http::response(['data' => ['id' => 9]], 201),
    ]);

    $api = app(SenderApi::class);

    expect($api->ensureList('Пользователи MagExpert', 'Описание'))->toBe(9)
        ->and($api->ensureList('Пользователи MagExpert'))->toBe(9);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://sender.test/api/sender/v1/lists'
        && $request->data() === ['name' => 'Пользователи MagExpert', 'description' => 'Описание']);
});

it('takes the list created by a parallel request when the name is already taken', function (): void {
    Http::fake([
        'sender.test/api/sender/v1/lists?*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => 5, 'name' => 'Пользователи MagExpert']]]),
        'sender.test/api/sender/v1/lists' => Http::response(['message' => 'База с таким названием уже есть', 'list_id' => 5], 409),
    ]);

    expect(app(SenderApi::class)->ensureList('Пользователи MagExpert'))->toBe(5);
});

it('splits the profile into base fields and attributes', function (): void {
    Http::fake(['sender.test/*' => Http::response(['data' => ['email' => 'anna@example.com', 'status' => 'subscribed', 'created' => true]], 201)]);

    app(SenderApi::class)->upsertContact(7, 'Anna@Example.com', 'Анна', ['phone' => '+79991234567', 'city' => 'Москва', 'specialization' => 'Гинеколог'], true, ['a']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://sender.test/api/sender/v1/lists/7/contacts/anna%40example.com'
        && $request->data() === [
            'name' => 'Анна', 'phone' => '+79991234567', 'city' => 'Москва',
            'attributes' => ['specialization' => 'Гинеколог'], 'subscribed' => true, 'tags' => ['a'],
        ]);
});
