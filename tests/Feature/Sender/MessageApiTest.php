<?php

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Services\ApiKeyService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);

    $domain = $this->organization->domains()->create([
        'domain' => 'mag-expert.ru',
        'verification_token' => 'token',
        'status' => Domain::STATUS_VERIFIED,
        'dkim_selector' => 'mail',
    ]);

    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'noreply@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->organization->templates()->create([
        'slug' => 'welcome',
        'name' => 'Приветствие',
        'subject' => 'Добро пожаловать, {{ name }}',
        'body_html' => '<p>Привет, {{ name }}</p>',
        'sender_address_id' => $sender->id,
    ]);

    $this->key = (new ApiKeyService)->create($this->organization, 'tests')['plain'];

    $this->payload = [
        'template' => 'welcome',
        'to' => 'user@example.com',
        'data' => ['name' => 'Анна'],
    ];
});

it('rejects requests without a valid api key', function (): void {
    $this->postJson('/api/sender/v1/messages', $this->payload)->assertUnauthorized();

    $this->withToken('mxs_wrong')->postJson('/api/sender/v1/messages', $this->payload)->assertUnauthorized();
});

it('accepts a message and returns its status', function (): void {
    Queue::fake();

    $response = $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload);

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('subject', 'Добро пожаловать, Анна');

    Queue::assertPushed(SendMessageJob::class);

    $this->withToken($this->key)
        ->getJson('/api/sender/v1/messages/'.$response->json('id'))
        ->assertOk()
        ->assertJsonPath('to', 'user@example.com');
});

it('validates the request', function (): void {
    $this->withToken($this->key)
        ->postJson('/api/sender/v1/messages', ['to' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['template', 'to'])
        ->assertJsonMissingValidationErrors('from');
});

it('returns 404 for an unknown template', function (): void {
    $this->withToken($this->key)
        ->postJson('/api/sender/v1/messages', [...$this->payload, 'template' => 'missing'])
        ->assertNotFound();
});

it('returns 422 when the template has no sender address', function (): void {
    Queue::fake();
    $this->organization->templates()->where('slug', 'welcome')->update(['sender_address_id' => null]);

    $this->withToken($this->key)
        ->postJson('/api/sender/v1/messages', $this->payload)
        ->assertUnprocessable()
        ->assertJsonPath('status', 'blocked');
});

it('ignores the sender passed in the request', function (): void {
    Queue::fake();

    $this->withToken($this->key)
        ->postJson('/api/sender/v1/messages', [...$this->payload, 'from' => 'evil@other.ru', 'from_name' => 'Evil'])
        ->assertAccepted();

    $message = $this->organization->messages()->latest('id')->first();

    expect($message->from_email)->toBe('noreply@mag-expert.ru')
        ->and($message->from_name)->toBe('MagExpert');
});

it('does not show messages of another organization', function (): void {
    Queue::fake();

    $uuid = $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload)->json('id');

    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $otherKey = (new ApiKeyService)->create($other, 'other')['plain'];

    $this->withToken($otherKey)->getJson('/api/sender/v1/messages/'.$uuid)->assertNotFound();
});

it('does not create a second message for a repeated Idempotency-Key', function (): void {
    Queue::fake();

    $first = $this->withToken($this->key)->withHeader('Idempotency-Key', 'reg-63-user-7')
        ->postJson('/api/sender/v1/messages', $this->payload)->assertAccepted();

    $second = $this->withToken($this->key)->withHeader('Idempotency-Key', 'reg-63-user-7')
        ->postJson('/api/sender/v1/messages', $this->payload)->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($this->organization->messages()->count())->toBe(1);

    Queue::assertPushed(SendMessageJob::class, 1);
});

it('rejects an Idempotency-Key reused for another letter', function (): void {
    Queue::fake();

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'k-1')
        ->postJson('/api/sender/v1/messages', $this->payload)->assertAccepted();

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'k-1')
        ->postJson('/api/sender/v1/messages', [...$this->payload, 'to' => 'other@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Idempotency-Key уже использован для другого письма');

    expect($this->organization->messages()->count())->toBe(1);
});

it('keeps sending separate messages when there is no Idempotency-Key', function (): void {
    Queue::fake();

    $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload)->assertAccepted();
    $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload)->assertAccepted();

    expect($this->organization->messages()->count())->toBe(2);
});

it('validates the Idempotency-Key header', function (): void {
    $this->withToken($this->key)->withHeader('Idempotency-Key', str_repeat('a', 256))
        ->postJson('/api/sender/v1/messages', $this->payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('answers errors in application/problem+json even without an Accept header', function (): void {
    $this->withToken($this->key)->withHeaders(['Accept' => '*/*'])
        ->post('/api/sender/v1/messages', ['template' => 'missing', 'to' => 'user@example.com'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('title', 'Not Found')
        ->assertJsonPath('detail', 'Шаблон не найден')
        ->assertJsonPath('message', 'Шаблон не найден');

    $this->flushHeaders()->withHeaders(['Accept' => '*/*'])->post('/api/sender/v1/messages', $this->payload)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 401);

    $this->flushHeaders()->withToken($this->key)->withHeaders(['Accept' => '*/*'])
        ->put('/api/sender/v1/subscriptions', ['email' => 'not-an-email', 'subscribed' => true])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'message', 'errors' => ['email']]);
});

it('keeps the blocked message as a resource rather than an error', function (): void {
    Queue::fake();
    $this->organization->templates()->where('slug', 'welcome')->update(['sender_address_id' => null]);

    $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload)
        ->assertUnprocessable()
        ->assertJsonPath('status', 'blocked')
        ->assertJsonStructure(['id', 'to', 'subject', 'error']);
});

it('removes an address from every list of the organization', function (): void {
    $first = $this->organization->lists()->create(['name' => 'Первая']);
    $second = $this->organization->lists()->create(['name' => 'Вторая']);
    $first->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com']);
    $second->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com']);
    $second->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'petr@example.com']);

    $this->withToken($this->key)->deleteJson('/api/sender/v1/contacts/Anna@Example.com')->assertNoContent();

    expect($this->organization->contacts()->pluck('email')->all())->toBe(['petr@example.com']);

    // повторное удаление ничего не ломает
    $this->withToken($this->key)->deleteJson('/api/sender/v1/contacts/anna@example.com')->assertNoContent();
});
