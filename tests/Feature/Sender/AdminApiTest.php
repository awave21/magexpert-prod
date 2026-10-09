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

it('moves a template between folders and out of a folder', function (): void {
    $template = $this->organization->templates()->create(['slug' => 'welcome', 'name' => 'Привет', 'subject' => 'Тема', 'body_html' => '<p>Текст</p>']);
    $folder = $this->organization->templateFolders()->create(['name' => 'Сложный пациент']);

    $this->withToken($this->token)->patchJson(adminApi()."/templates/{$template->id}/folder", ['folder_id' => $folder->id])
        ->assertOk()
        ->assertJsonPath('data.folder_id', $folder->id);

    $this->withToken($this->token)->getJson(adminApi().'/template-folders')
        ->assertJsonPath('data.0.templates_count', 1);

    $this->withToken($this->token)->patchJson(adminApi()."/templates/{$template->id}/folder", ['folder_id' => null])
        ->assertOk()
        ->assertJsonPath('data.folder_id', null);
});

it('does not move a template into a folder of another organization', function (): void {
    $template = $this->organization->templates()->create(['slug' => 'welcome', 'name' => 'Привет', 'subject' => 'Тема', 'body_html' => 'X']);
    $foreignFolder = $this->other->templateFolders()->create(['name' => 'Чужая']);
    $foreignTemplate = $this->other->templates()->create(['slug' => 'secret', 'name' => 'X', 'subject' => 'X', 'body_html' => 'X']);

    $this->withToken($this->token)->patchJson(adminApi()."/templates/{$template->id}/folder", ['folder_id' => $foreignFolder->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('folder_id');

    $this->withToken($this->token)->patchJson(adminApi()."/templates/{$foreignTemplate->id}/folder", ['folder_id' => null])
        ->assertNotFound();

    $this->withToken($this->token)->patchJson(adminApi()."/templates/{$template->id}/folder", [])
        ->assertJsonValidationErrors('folder_id');
});

it('registers an organization with its first user', function (): void {
    $this->postJson(adminApi().'/register', [
        'organization' => 'Клиника Здоровье', 'name' => 'Анна', 'email' => 'Anna@Clinic.ru', 'password' => 'long-password',
    ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'organization']])
        ->assertJsonPath('user.email', 'anna@clinic.ru');

    $this->postJson(adminApi().'/register', [
        'organization' => 'Другая', 'name' => 'Анна', 'email' => 'admin@example.com', 'password' => 'long-password',
    ])->assertJsonValidationErrors('email');
});

it('applies default values of custom variables when sending', function (): void {
    $this->withToken($this->token)->postJson(adminApi().'/variables', ['key' => 'clinic', 'label' => 'Клиника', 'default_value' => 'МедАльянс'])
        ->assertCreated();
    $template = $this->organization->templates()->create(['slug' => 'v', 'name' => 'V', 'subject' => 'Из {{ clinic }}', 'body_html' => 'X']);

    $this->withToken($this->token)->postJson(adminApi()."/templates/{$template->id}/preview", ['data' => []])
        ->assertJsonPath('data.subject', 'Из МедАльянс');
    $this->withToken($this->token)->postJson(adminApi()."/templates/{$template->id}/preview", ['data' => ['clinic' => 'Другая']])
        ->assertJsonPath('data.subject', 'Из Другая');
});

it('sends a test email from the first verified domain', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $template = $this->organization->templates()->create(['slug' => 'welcome', 'name' => 'Привет', 'subject' => 'Здравствуйте, {{ name }}', 'body_html' => 'X']);

    $this->withToken($this->token)->postJson(adminApi()."/templates/{$template->id}/test", ['to' => 'me@example.com'])
        ->assertUnprocessable();

    $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => 'verified', 'verified_at' => now(), 'dkim_selector' => 'mail']);

    $this->withToken($this->token)->postJson(adminApi()."/templates/{$template->id}/test", ['to' => 'Me@Example.com', 'data' => ['name' => 'Анна']])
        ->assertCreated()
        ->assertJsonPath('data.status', Message::STATUS_QUEUED)
        ->assertJsonPath('data.to', 'me@example.com')
        ->assertJsonPath('data.from', 'noreply@mag-expert.ru')
        ->assertJsonPath('data.subject', 'Здравствуйте, Анна');

    Illuminate\Support\Facades\Queue::assertPushed(App\Sender\Jobs\SendMessageJob::class);
});

it('stores a block design together with the rendered html', function (): void {
    $design = ['settings' => ['width' => 600], 'blocks' => [['id' => 'a1', 'type' => 'text', 'html' => '<p>Привет</p>']]];

    $id = $this->withToken($this->token)->postJson(adminApi().'/templates', [
        'slug' => 'blocks', 'name' => 'Блоки', 'subject' => 'Тема', 'body_html' => '<table><tr><td>Привет</td></tr></table>',
        'editor' => 'blocks', 'design' => $design,
    ])->assertCreated()
        ->assertJsonPath('data.editor', 'blocks')
        ->assertJsonPath('data.design.blocks.0.type', 'text')
        ->json('data.id');

    $this->withToken($this->token)->getJson(adminApi()."/templates/{$id}")->assertJsonPath('data.design.settings.width', 600);

    $this->withToken($this->token)->postJson(adminApi().'/templates', [
        'slug' => 'broken', 'name' => 'X', 'subject' => 'X', 'body_html' => 'X', 'editor' => 'blocks',
    ])->assertJsonValidationErrors('design');
});

it('uploads an image for an email and rejects other files', function (): void {
    Illuminate\Support\Facades\Storage::fake('public');

    $url = $this->withToken($this->token)->post(adminApi().'/assets', [
        'file' => Illuminate\Http\UploadedFile::fake()->image('cover.jpg', 1200, 500),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.width', 1200)
        ->assertJsonPath('data.name', 'cover.jpg')
        ->json('data.url');

    expect($url)->toContain('/storage/sender/'.$this->organization->id.'/');

    $this->withToken($this->token)->post(adminApi().'/assets', [
        'file' => Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10),
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
});

it('manages sender addresses on own domains only', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => 'verified', 'verified_at' => now(), 'dkim_selector' => 'mail']);

    $id = $this->withToken($this->token)->postJson(adminApi().'/sender-addresses', ['email' => 'Events@Mag-Expert.ru', 'name' => 'МагЭксперт'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'events@mag-expert.ru')
        ->assertJsonPath('data.verified', true)
        ->json('data.id');

    $this->withToken($this->token)->postJson(adminApi().'/sender-addresses', ['email' => 'events@mag-expert.ru', 'name' => 'Дубль'])
        ->assertJsonValidationErrors('email');
    $this->withToken($this->token)->postJson(adminApi().'/sender-addresses', ['email' => 'me@gmail.com', 'name' => 'Чужой'])
        ->assertJsonValidationErrors('email');

    $this->withToken($this->token)->putJson(adminApi()."/sender-addresses/{$id}", ['name' => 'Команда МагЭксперт', 'email' => 'other@mag-expert.ru'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Команда МагЭксперт')
        ->assertJsonPath('data.email', 'events@mag-expert.ru');

    $this->withToken($this->token)->getJson(adminApi().'/sender-addresses')->assertJsonCount(1, 'data');
});

it('sends from the sender address chosen in the template', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => 'verified', 'verified_at' => now(), 'dkim_selector' => 'mail']);
    $address = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'events@mag-expert.ru', 'name' => 'МагЭксперт', 'confirmed_at' => now()]);
    $foreign = $this->other->senderAddresses()->create([
        'domain_id' => $this->other->domains()->create(['domain' => 'other.ru', 'verification_token' => 't', 'status' => 'verified', 'dkim_selector' => 'mail'])->id,
        'email' => 'x@other.ru', 'name' => 'X',
    ]);

    $id = $this->withToken($this->token)->postJson(adminApi().'/templates', [
        'slug' => 'welcome', 'name' => 'Привет', 'subject' => 'Тема', 'body_html' => '<html><body><p>Текст</p></body></html>',
        'sender_address_id' => $address->id, 'reply_to' => 'support@mag-expert.ru', 'preheader' => 'Ждём вас, {{ name }}',
    ])->assertCreated()->assertJsonPath('data.sender_address_id', $address->id)->json('data.id');

    $this->withToken($this->token)->postJson(adminApi().'/templates', [
        'slug' => 'foreign', 'name' => 'X', 'subject' => 'X', 'body_html' => 'X', 'sender_address_id' => $foreign->id,
    ])->assertJsonValidationErrors('sender_address_id');

    $plain = app(App\Sender\Services\ApiKeyService::class)->create($this->organization, 'site')['plain'];

    // приложению достаточно ID шаблона, получателя и данных
    $uuid = $this->withToken($plain)->postJson('/api/sender/v1/messages', ['template' => $id, 'to' => 'anna@example.com', 'data' => ['name' => 'Анна']])
        ->assertAccepted()
        ->json('id');

    $message = Message::query()->where('uuid', $uuid)->firstOrFail();
    expect($message->from_email)->toBe('events@mag-expert.ru')
        ->and($message->from_name)->toBe('МагЭксперт')
        ->and($message->reply_to)->toBe('support@mag-expert.ru');

    $html = app(App\Sender\Services\TemplateRenderer::class)->render($message->template, ['name' => 'Анна'])['html'];
    expect($html)->toContain('<body><div style="display:none')->toContain('Ждём вас, Анна');

    // без адреса в шаблоне и в запросе письмо блокируется с понятной причиной
    $bare = $this->organization->templates()->create(['slug' => 'bare', 'name' => 'B', 'subject' => 'B', 'body_html' => 'B']);
    $this->withToken($plain)->postJson('/api/sender/v1/messages', ['template' => $bare->slug, 'to' => 'anna@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'blocked');
});

it('confirms a sender address by the link from the email', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Queue::fake();
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => 'verified', 'verified_at' => now(), 'dkim_selector' => 'mail']);

    $id = $this->withToken($this->token)->postJson(adminApi().'/sender-addresses', ['email' => 'events@mag-expert.ru', 'name' => 'МагЭксперт'])
        ->assertCreated()
        ->assertJsonPath('data.confirmed', false)
        ->json('data.id');

    Illuminate\Support\Facades\Mail::assertSentCount(1);
    $address = App\Sender\Models\SenderAddress::query()->findOrFail($id);
    expect($address->confirmation_token)->not->toBeNull();

    // пока адрес не подтверждён, письма шаблона блокируются
    $template = $this->organization->templates()->create(['slug' => 't', 'name' => 'T', 'subject' => 'T', 'body_html' => 'T', 'sender_address_id' => $id]);
    $message = app(App\Sender\Services\MessageService::class)->send($this->organization, $template, 'anna@example.com');
    expect($message->status)->toBe(Message::STATUS_BLOCKED)->and($message->error)->toContain('не подтверждён');

    // повторная отправка не чаще раза в минуту
    $this->withToken($this->token)->postJson(adminApi()."/sender-addresses/{$id}/resend")->assertStatus(429);

    // ссылку подменяем известным токеном, как будто открыли письмо
    $address->forceFill(['confirmation_token' => hash('sha256', str_repeat('a', 48))])->save();
    $this->get('/sender/confirm-address/'.str_repeat('b', 48))->assertStatus(410);
    $this->get('/sender/confirm-address/'.str_repeat('a', 48))->assertOk()->assertSee('Адрес подтверждён');

    expect($address->fresh()->isConfirmed())->toBeTrue();
    $this->withToken($this->token)->getJson(adminApi().'/sender-addresses')->assertJsonPath('data.0.confirmed', true);
    $this->withToken($this->token)->postJson(adminApi()."/sender-addresses/{$id}/resend")->assertUnprocessable();

    $message = app(App\Sender\Services\MessageService::class)->send($this->organization, $template->refresh(), 'anna@example.com');
    expect($message->status)->toBe(Message::STATUS_QUEUED);
});

it('expires the confirmation link after two days', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => 'verified', 'dkim_selector' => 'mail']);
    $address = $this->organization->senderAddresses()->create([
        'domain_id' => $domain->id, 'email' => 'a@mag-expert.ru', 'name' => 'A',
        'confirmation_token' => hash('sha256', str_repeat('c', 48)), 'confirmation_sent_at' => now()->subHours(49),
    ]);

    $this->get('/sender/confirm-address/'.str_repeat('c', 48))->assertStatus(410);
    expect($address->fresh()->isConfirmed())->toBeFalse();
});
