<?php

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Services\ApiKeyService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);

    $this->organization->domains()->create([
        'domain' => 'mag-expert.ru',
        'verification_token' => 'token',
        'status' => Domain::STATUS_VERIFIED,
        'dkim_selector' => 'mail',
    ]);

    $this->organization->templates()->create([
        'slug' => 'welcome',
        'name' => 'Приветствие',
        'subject' => 'Добро пожаловать, {{ name }}',
        'body_html' => '<p>Привет, {{ name }}</p>',
    ]);

    $this->key = (new ApiKeyService)->create($this->organization, 'tests')['plain'];

    $this->payload = [
        'template' => 'welcome',
        'to' => 'user@example.com',
        'from' => 'noreply@mag-expert.ru',
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

it('returns 422 when the sender domain is not allowed', function (): void {
    Queue::fake();

    $this->withToken($this->key)
        ->postJson('/api/sender/v1/messages', [...$this->payload, 'from' => 'noreply@other.ru'])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'blocked');
});

it('does not show messages of another organization', function (): void {
    Queue::fake();

    $uuid = $this->withToken($this->key)->postJson('/api/sender/v1/messages', $this->payload)->json('id');

    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $otherKey = (new ApiKeyService)->create($other, 'other')['plain'];

    $this->withToken($otherKey)->getJson('/api/sender/v1/messages/'.$uuid)->assertNotFound();
});
