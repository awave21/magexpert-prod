<?php

use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);

    $this->user = User::create([
        'organization_id' => $this->organization->id,
        'name' => 'Админ',
        'email' => 'admin@example.com',
        'password' => 'secret-pass',
    ]);

    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $this->other = Organization::create(['name' => 'Other', 'slug' => 'other']);
});

function adminApi(): string
{
    return '/api/sender/v1/admin';
}

it('logs in and returns a token', function (): void {
    $this->postJson(adminApi().'/login', ['email' => 'Admin@Example.com', 'password' => 'secret-pass'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'organization']])
        ->assertJsonPath('user.organization', 'MagExpert');
});

it('rejects a wrong password and an inactive user', function (): void {
    $this->postJson(adminApi().'/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertUnprocessable();

    $this->user->update(['is_active' => false]);

    $this->postJson(adminApi().'/login', ['email' => 'admin@example.com', 'password' => 'secret-pass'])->assertUnprocessable();
});

it('requires a token for admin endpoints', function (): void {
    $this->getJson(adminApi().'/me')->assertUnauthorized();
    $this->withToken('mxu_wrong')->getJson(adminApi().'/me')->assertUnauthorized();
});

it('returns the current user and logs out', function (): void {
    $this->withToken($this->token)->getJson(adminApi().'/me')->assertOk()->assertJsonPath('data.email', 'admin@example.com');

    $this->withToken($this->token)->postJson(adminApi().'/logout')->assertOk();

    $this->withToken($this->token)->getJson(adminApi().'/me')->assertUnauthorized();
});

it('adds a domain and lists dns records', function (): void {
    $response = $this->withToken($this->token)->postJson(adminApi().'/domains', ['domain' => 'Mag-Expert.ru'])
        ->assertCreated()
        ->assertJsonPath('data.domain', 'mag-expert.ru')
        ->assertJsonPath('data.status', Domain::STATUS_PENDING)
        ->assertJsonCount(4, 'data.dns_records');

    expect($response->json('data'))->not->toHaveKey('dkim_private_key');

    $this->withToken($this->token)->getJson(adminApi().'/domains')->assertOk()->assertJsonCount(1, 'data');
});

it('validates a domain', function (): void {
    $this->withToken($this->token)->postJson(adminApi().'/domains', ['domain' => 'not a domain'])
        ->assertUnprocessable()->assertJsonValidationErrors('domain');

    $this->withToken($this->token)->postJson(adminApi().'/domains', ['domain' => 'mag-expert.ru'])->assertCreated();
    $this->withToken($this->token)->postJson(adminApi().'/domains', ['domain' => 'mag-expert.ru'])
        ->assertUnprocessable()->assertJsonValidationErrors('domain');
});

it('does not expose domains of another organization', function (): void {
    $domain = $this->other->domains()->create([
        'domain' => 'other.ru', 'verification_token' => 't', 'status' => 'pending', 'dkim_selector' => 'mail',
    ]);

    $this->withToken($this->token)->getJson(adminApi().'/domains')->assertJsonCount(0, 'data');
    $this->withToken($this->token)->getJson(adminApi().'/domains/'.$domain->id)->assertNotFound();
    $this->withToken($this->token)->postJson(adminApi().'/domains/'.$domain->id.'/verify')->assertNotFound();
    $this->withToken($this->token)->deleteJson(adminApi().'/domains/'.$domain->id)->assertNotFound();
});

it('creates, updates, previews and deletes a template', function (): void {
    $payload = [
        'slug' => 'welcome', 'name' => 'Приветствие', 'subject' => 'Привет, {{ name }}',
        'body_html' => '<p>Здравствуйте, {{ name }}</p>',
    ];

    $id = $this->withToken($this->token)->postJson(adminApi().'/templates', $payload)
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson(adminApi().'/templates', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('slug');

    $this->withToken($this->token)->putJson(adminApi().'/templates/'.$id, [...$payload, 'subject' => 'Новая тема'])
        ->assertOk()->assertJsonPath('data.subject', 'Новая тема');

    $this->withToken($this->token)->postJson(adminApi().'/templates/'.$id.'/preview', ['data' => ['name' => 'Анна']])
        ->assertOk()->assertJsonPath('data.html', '<p>Здравствуйте, Анна</p>');

    $this->withToken($this->token)->deleteJson(adminApi().'/templates/'.$id)->assertOk();
    $this->withToken($this->token)->getJson(adminApi().'/templates')->assertJsonCount(0, 'data');
});

it('keeps the same template slug allowed in another organization', function (): void {
    $this->other->templates()->create(['slug' => 'welcome', 'name' => 'X', 'subject' => 'X', 'body_html' => 'X']);

    $this->withToken($this->token)->postJson(adminApi().'/templates', [
        'slug' => 'welcome', 'name' => 'Приветствие', 'subject' => 'Тема', 'body_html' => 'Текст',
    ])->assertCreated();
});

it('creates an api key once and revokes it', function (): void {
    $response = $this->withToken($this->token)->postJson(adminApi().'/api-keys', ['name' => 'Сайт'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'name', 'key_prefix'], 'plain']);

    expect($response->json('plain'))->toStartWith('mxs_')
        ->and($response->json('data'))->not->toHaveKey('key_hash');

    $id = $response->json('data.id');

    $this->withToken($this->token)->deleteJson(adminApi().'/api-keys/'.$id)->assertOk();

    $this->withToken($this->token)->getJson(adminApi().'/api-keys')
        ->assertJsonPath('data.0.revoked_at', fn ($value) => $value !== null);
});

it('lists messages with filters and hides other organizations', function (): void {
    $make = fn (Organization $organization, string $to, string $status) => $organization->messages()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'to_email' => $to, 'from_email' => 'a@b.ru',
        'subject' => 'Тема', 'status' => $status, 'data' => ['name' => 'Анна'],
    ]);

    $sent = $make($this->organization, 'anna@example.com', Message::STATUS_SENT);
    $make($this->organization, 'boris@example.com', Message::STATUS_FAILED);
    $make($this->other, 'secret@example.com', Message::STATUS_SENT);

    $this->withToken($this->token)->getJson(adminApi().'/messages')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->token)->getJson(adminApi().'/messages?status=failed')->assertJsonCount(1, 'data');
    $this->withToken($this->token)->getJson(adminApi().'/messages?q=anna')->assertJsonCount(1, 'data');

    $this->withToken($this->token)->getJson(adminApi().'/messages/'.$sent->uuid)
        ->assertOk()->assertJsonPath('data.variables.name', 'Анна');
});

it('manages the suppression list', function (): void {
    $id = $this->withToken($this->token)->postJson(adminApi().'/suppressions', ['email' => 'Bad@Example.com'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'bad@example.com')
        ->assertJsonPath('data.reason', Suppression::REASON_MANUAL)
        ->json('data.id');

    $this->withToken($this->token)->postJson(adminApi().'/suppressions', ['email' => 'bad@example.com'])->assertCreated();

    $this->withToken($this->token)->getJson(adminApi().'/suppressions')->assertJsonCount(1, 'data');

    $this->withToken($this->token)->deleteJson(adminApi().'/suppressions/'.$id)->assertOk();
    $this->withToken($this->token)->getJson(adminApi().'/suppressions')->assertJsonCount(0, 'data');
});

it('returns overview stats for the own organization only', function (): void {
    $make = fn (Organization $organization, string $status) => $organization->messages()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'to_email' => 'a@example.com', 'from_email' => 'a@b.ru',
        'subject' => 'Тема', 'status' => $status,
    ]);

    $make($this->organization, Message::STATUS_SENT);
    $make($this->organization, Message::STATUS_SENT);
    $make($this->organization, Message::STATUS_FAILED);
    $make($this->organization, Message::STATUS_QUEUED);
    $make($this->other, Message::STATUS_SENT);
    $this->organization->domains()->create(['domain' => 'new.ru', 'verification_token' => 't', 'status' => 'pending', 'dkim_selector' => 'mail']);

    $this->withToken($this->token)->getJson(adminApi().'/stats')
        ->assertOk()
        ->assertJsonPath('totals.sent', 2)
        ->assertJsonPath('totals.failed', 1)
        ->assertJsonPath('totals.queued', 1)
        ->assertJsonPath('unverified_domains', 1)
        ->assertJsonCount(7, 'days')
        ->assertJsonPath('days.6.sent', 2)
        ->assertJsonCount(4, 'recent');
});
