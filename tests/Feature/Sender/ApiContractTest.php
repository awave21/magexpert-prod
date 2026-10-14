<?php

use App\Sender\Http\Middleware\AuthenticateApiKey;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Services\ApiKeyService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Контрактные тесты публичного API Sender: документ docs/sender-api.openapi.yaml и реальные ответы не расходятся.
 */
function senderApiSpec(): array
{
    return Yaml::parseFile(base_path('docs/sender-api.openapi.yaml'));
}

/**
 * Проверка значения по схеме OpenAPI: $ref, type (в том числе список типов), required, properties, enum, items.
 *
 * @return list<string> найденные расхождения
 */
function senderSchemaErrors(mixed $value, array $schema, array $spec, string $path = '$'): array
{
    while (isset($schema['$ref'])) {
        $resolved = $spec;

        foreach (explode('/', ltrim($schema['$ref'], '#/')) as $part) {
            $resolved = $resolved[$part];
        }

        $schema = $resolved;
    }

    $errors = [];
    $types = (array) ($schema['type'] ?? []);

    if ($types !== []) {
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'unknown',
        };

        // пустой массив PHP одинаково может быть и списком, и объектом
        $matches = in_array($actual, $types, true)
            || ($actual === 'integer' && in_array('number', $types, true))
            || ($value === [] && (in_array('object', $types, true) || in_array('array', $types, true)));

        if (! $matches) {
            return ["{$path}: ожидался тип ".implode('|', $types).", получен {$actual}"];
        }
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path}: значение ".json_encode($value, JSON_UNESCAPED_UNICODE).' не входит в enum';
    }

    if (is_array($value) && ! array_is_list($value)) {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}: нет обязательного поля {$key}";
            }
        }

        foreach ($schema['properties'] ?? [] as $key => $sub) {
            if (array_key_exists($key, $value)) {
                $errors = [...$errors, ...senderSchemaErrors($value[$key], $sub, $spec, "{$path}.{$key}")];
            }
        }
    }

    if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...senderSchemaErrors($item, $schema['items'], $spec, "{$path}[{$index}]")];
        }
    }

    return $errors;
}

function senderResponseSchema(array $spec, string $path, string $method, int $status, string $mime = 'application/json'): array
{
    $response = $spec['paths'][$path][$method]['responses'][(string) $status] ?? null;
    expect($response)->not->toBeNull("{$method} {$path}: ответ {$status} не описан в контракте");

    while (isset($response['$ref'])) {
        $response = $spec['components']['responses'][basename($response['$ref'])];
    }

    return $response['content'][$mime]['schema'] ?? [];
}

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $domain = $this->organization->domains()->create([
        'domain' => 'mag-expert.ru', 'verification_token' => 'token', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail',
    ]);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'noreply@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();
    $this->organization->templates()->create([
        'slug' => 'welcome', 'name' => 'Приветствие', 'subject' => 'Добро пожаловать, {{ name }}',
        'body_html' => '<p>Привет, {{ name }}</p>', 'sender_address_id' => $sender->id,
    ]);

    $this->token = (new ApiKeyService)->create($this->organization, 'contract')['plain'];
    $this->spec = senderApiSpec();
    $this->base = '/api/sender/v1';
});

it('documents every public API route and only them', function (): void {
    $documented = [];

    foreach ($this->spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array(AuthenticateApiKey::class, $route->gatherMiddleware(), true))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' /'.preg_replace('~^api/sender/v1/~', '', $route->uri())))
        ->unique()
        ->values()
        ->all();

    expect($actual)->toEqualCanonicalizing($documented);
});

it('keeps responses of the public API within the documented schemas', function (): void {
    Queue::fake();
    $api = fn () => $this->withToken($this->token);

    $created = $api()->postJson("{$this->base}/messages", ['template' => 'welcome', 'to' => 'anna@example.com', 'data' => ['name' => 'Анна']])->assertAccepted();
    expect(senderSchemaErrors($created->json(), senderResponseSchema($this->spec, '/messages', 'post', 202), $this->spec))->toBe([]);

    $shown = $api()->getJson("{$this->base}/messages/".$created->json('id'))->assertOk();
    expect(senderSchemaErrors($shown->json(), senderResponseSchema($this->spec, '/messages/{uuid}', 'get', 200), $this->spec))->toBe([]);

    $subscription = $api()->putJson("{$this->base}/subscriptions", ['email' => 'anna@example.com', 'subscribed' => false])->assertOk();
    expect(senderSchemaErrors($subscription->json(), senderResponseSchema($this->spec, '/subscriptions', 'put', 200), $this->spec))->toBe([]);

    $list = $api()->putJson("{$this->base}/lists", ['name' => 'Пользователи сайта'])->assertCreated();
    expect(senderSchemaErrors($list->json(), senderResponseSchema($this->spec, '/lists', 'put', 201), $this->spec))->toBe([]);
    $listId = $list->json('data.id');

    $contact = $api()->putJson("{$this->base}/lists/{$listId}/contacts", [
        'email' => 'Anna@Example.com', 'name' => 'Анна', 'data' => ['city' => 'Москва'], 'subscribed' => true, 'tags' => ['Shkola-Ginekologii', '2026-11-15'],
    ])->assertCreated();
    expect(senderSchemaErrors($contact->json(), senderResponseSchema($this->spec, '/lists/{list}/contacts', 'put', 201), $this->spec))->toBe([]);

    $batch = $api()->postJson("{$this->base}/lists/{$listId}/contacts/batch", ['contacts' => [
        ['email' => 'petr@example.com', 'subscribed' => true],
        ['email' => 'not-an-email'],
    ]])->assertOk();
    expect(senderSchemaErrors($batch->json(), senderResponseSchema($this->spec, '/lists/{list}/contacts/batch', 'post', 200), $this->spec))->toBe([])
        ->and($batch->json('data.invalid.0.index'))->toBe(1);

    $one = $api()->getJson("{$this->base}/lists/{$listId}/contacts/anna@example.com")->assertOk();
    expect(senderSchemaErrors($one->json(), senderResponseSchema($this->spec, '/lists/{list}/contacts/{email}', 'get', 200), $this->spec))->toBe([])
        ->and($one->json('data.tags'))->toBe(['shkola-ginekologii', '2026-11-15']);

    $api()->deleteJson("{$this->base}/lists/{$listId}/contacts/anna@example.com")->assertNoContent();
});

it('answers every documented error as the Problem schema', function (): void {
    $problem = $this->spec['components']['schemas']['Problem'];

    $responses = [
        $this->flushHeaders()->postJson("{$this->base}/messages", [])->assertUnauthorized(),
        $this->withToken($this->token)->postJson("{$this->base}/messages", ['template' => 'missing', 'to' => 'a@example.com'])->assertNotFound(),
        $this->withToken($this->token)->postJson("{$this->base}/messages", ['to' => 'not-an-email'])->assertUnprocessable(),
        $this->withToken($this->token)->getJson("{$this->base}/lists/999/contacts/a@example.com")->assertNotFound(),
        $this->withToken($this->token)->putJson("{$this->base}/subscriptions", ['email' => 'x'])->assertUnprocessable(),
    ];

    foreach ($responses as $response) {
        $response->assertHeader('Content-Type', 'application/problem+json');
        expect(senderSchemaErrors($response->json(), $problem, $this->spec))->toBe([]);
    }
});

it('documents the schema checker itself: a wrong type and a missing field are reported', function (): void {
    $schema = $this->spec['components']['schemas']['Message'];

    expect(senderSchemaErrors(['id' => 1, 'status' => 'queued'], $schema, $this->spec))
        ->toContain('$.id: ожидался тип string, получен integer')
        ->toContain('$: нет обязательного поля subject');
});

it('serves the OpenAPI specification and the documentation pages without a key', function (): void {
    $spec = $this->get('/api/sender/v1/openapi.yaml')->assertOk();
    expect($spec->headers->get('Content-Type'))->toContain('application/yaml');

    $parsed = Yaml::parse($spec->getContent());
    expect($parsed['openapi'])->toBe('3.1.0')
        ->and($parsed['paths'])->toHaveKey('/messages')
        ->and($parsed['paths'])->toHaveKey('/contacts/{email}');

    $this->get('/api/sender/v1/docs/swagger')->assertOk()
        ->assertSee('swagger-ui-bundle.js', false)
        ->assertSee('/api/sender/v1/openapi.yaml', false);

    $this->get('/api/sender/v1/docs/redoc')->assertOk()
        ->assertSee('redoc.standalone.js', false)
        ->assertSee('/api/sender/v1/openapi.yaml', false);

    $this->get('/api/sender/v1/docs')->assertOk()
        ->assertSee('Swagger UI')
        ->assertSee('Redoc');
});

it('points the specification server at the address it was opened from', function (): void {
    $yaml = $this->get('http://sender.example.test/api/sender/v1/openapi.yaml')->assertOk()->getContent();

    expect(Yaml::parse($yaml)['servers'][0]['url'])->toBe('http://sender.example.test/api/sender/v1');
});
