<?php

use App\Services\Sender\SenderApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\OpenApi;

/**
 * Контракт со стороны потребителя: запросы сайта к Sender соответствуют спецификации
 * (docs/sender-api.openapi.yaml), а фейковый Sender отвечает только тем, что в ней описано.
 * Если сайт начнёт слать поле, которого нет в контракте, или обратится к необъявленному методу, тест упадёт.
 */
beforeEach(function (): void {
    config(['services.sender.url' => 'https://sender.test', 'services.sender.api_key' => 'test-key']);

    $this->violations = [];
    $this->used = [];

    // фейковый Sender: проверяет запрос по контракту и отвечает примером из схемы успешного ответа
    Http::fake(function (Request $request) {
        $match = OpenApi::operation($request->method(), $request->url());

        if ($match === null) {
            $this->violations[] = "{$request->method()} {$request->url()}: такой операции нет в контракте";

            return Http::response(['message' => 'unknown'], 404);
        }

        $key = strtoupper($match['method']).' '.$match['path'];
        $this->used[$key] = true;

        if (! $request->hasHeader('Authorization')) {
            $this->violations[] = "{$key}: нет заголовка Authorization";
        }

        $schema = OpenApi::requestSchema($match['path'], $match['method']);

        if ($schema !== null) {
            foreach (OpenApi::errors($request->data(), $schema) as $error) {
                $this->violations[] = "{$key}: запрос: {$error}";
            }
        }

        $codes = array_map('intval', array_keys($match['operation']['responses']));
        $status = collect($codes)->first(fn (int $code): bool => $code >= 200 && $code < 300);
        $response = $match['operation']['responses'][(string) $status];
        $body = $response['content']['application/json']['schema'] ?? null;

        return $body === null
            ? Http::response(null, $status)
            : Http::response(OpenApi::example($body), $status);
    });
});

it('sends only requests that the contract describes and with valid bodies', function (): void {
    $api = app(SenderApi::class);

    $api->send('event-registration', 'anna@example.com', ['first_name' => 'Анна', 'speakers' => [['name' => 'Иван Иванов']]], 'registration-1', 'outbox-uuid-12345');
    $api->setSubscription('anna@example.com', true);
    $listId = $api->ensureList('Пользователи MagExpert', 'Все пользователи сайта');
    $api->upsertContact($listId, 'Anna@Example.com', 'Анна Иванова', ['city' => 'Москва', 'source' => 'magexpert'], true, ['shkola-ginekologii', '2026-11-15']);
    $api->upsertContacts($listId, [['email' => 'petr@example.com', 'name' => null, 'data' => ['city' => 'Казань'], 'subscribed' => false]]);
    $api->deleteContact($listId, 'anna@example.com');
    $api->deleteContactEverywhere('anna@example.com');

    expect($this->violations)->toBe([])
        ->and(array_keys($this->used))->toEqualCanonicalizing([
            'POST /messages',
            'PUT /subscriptions',
            'PUT /lists',
            'PUT /lists/{list}/contacts',
            'POST /lists/{list}/contacts/batch',
            'DELETE /lists/{list}/contacts/{email}',
            'DELETE /contacts/{email}',
        ]);
});

it('detects a field that the contract does not allow', function (): void {
    // страховка самого теста: невалидный адрес в теле запроса должен быть замечен
    app(SenderApi::class)->setSubscription('not-an-email', true);

    expect($this->violations)->not->toBe([])
        ->and($this->violations[0])->toContain('email');
});

it('reads the contract responses without losing the fields the site depends on', function (): void {
    $api = app(SenderApi::class);

    $result = $api->send('welcome', 'anna@example.com');

    expect($result['id'])->toBe('9f6c2a1e-0000-4000-8000-000000000001')
        ->and($result['status'])->toBe('queued')
        ->and($result['http'])->toBe(202)
        ->and($api->ensureList('Пользователи MagExpert'))->toBe(1)
        ->and($api->upsertContact(1, 'anna@example.com', null, [], true))->toBe('subscribed');
});
