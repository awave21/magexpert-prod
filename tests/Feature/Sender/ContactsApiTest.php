<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendSubscriptionConfirmationsJob;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\User;
use App\Sender\Services\ApiKeyService;
use App\Sender\Services\AuthService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class, SendSubscriptionConfirmationsJob::class]);

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $issued = (new ApiKeyService)->create($this->organization, 'site');
    $this->apiKey = $issued['key'];
    $this->key = $issued['plain'];

    $this->list = $this->organization->lists()->create(['name' => 'Сайт']);
});

function contactsUrl(ContactList $list, string $suffix = ''): string
{
    return '/api/sender/v1/lists/'.$list->id.'/contacts'.$suffix;
}

function requireConfirmation(ContactList $list): ContactList
{
    $list->forceFill(['require_confirmation' => true])->save();

    return $list->refresh();
}

it('requires a valid api key', function (): void {
    $this->putJson('/api/sender/v1/lists', ['name' => 'Сайт'])->assertUnauthorized();
    $this->putJson(contactsUrl($this->list), ['email' => 'anna@example.com'])->assertUnauthorized();
    $this->withToken('mxs_wrong')->getJson(contactsUrl($this->list, '/anna@example.com'))->assertUnauthorized();

    (new ApiKeyService)->revoke($this->apiKey);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Неверный или отозванный API-ключ');
    expect(Contact::query()->count())->toBe(0);
});

it('finds or creates a list by name ignoring case and spaces', function (): void {
    $created = $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => '  Участники вебинаров ', 'description' => 'С сайта'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Участники вебинаров')
        ->assertJsonPath('data.description', 'С сайта')
        ->assertJsonPath('data.require_confirmation', false)
        ->assertJsonPath('data.contacts_count', 0)
        ->assertJsonPath('data.subscribed_count', 0)
        ->assertJsonPath('data.pending_count', 0);

    expect($created->json('data'))->toHaveKeys(['id', 'created_at']);

    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => 'УЧАСТНИКИ ВЕБИНАРОВ'])
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.name', 'Участники вебинаров');

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com'])->assertCreated();
    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => 'сайт'])
        ->assertOk()
        ->assertJsonPath('data.id', $this->list->id)
        ->assertJsonPath('data.contacts_count', 1)
        ->assertJsonPath('data.subscribed_count', 1);

    expect($this->organization->lists()->count())->toBe(2);

    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Укажите название базы']);
    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => str_repeat('я', 121)])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('isolates lists of other organizations', function (): void {
    $other = Organization::create(['name' => 'Другая', 'slug' => 'other']);
    $foreign = $other->lists()->create(['name' => 'Сайт']);
    $foreign->contacts()->create(['organization_id' => $other->id, 'email' => 'anna@example.com']);

    $this->withToken($this->key)->putJson(contactsUrl($foreign), ['email' => 'petr@example.com'])
        ->assertNotFound()->assertJsonPath('message', 'База не найдена');
    $this->withToken($this->key)->postJson(contactsUrl($foreign, '/batch'), ['contacts' => [['email' => 'petr@example.com']]])
        ->assertNotFound();
    $this->withToken($this->key)->getJson(contactsUrl($foreign, '/anna@example.com'))->assertNotFound();
    $this->withToken($this->key)->deleteJson(contactsUrl($foreign, '/anna@example.com'))->assertNotFound();

    // одноимённая база другой организации не находится: создаётся своя
    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => 'Новая'])->assertCreated();
    expect($foreign->contacts()->count())->toBe(1)
        ->and($other->lists()->count())->toBe(1);
});

it('creates a subscribed contact and queues the address check', function (): void {
    $this->withToken($this->key)->putJson(contactsUrl($this->list), [
        'email' => '  Anna@Example.COM ',
        'name' => 'Анна',
        'data' => ['city' => 'Москва', 'visits' => 3, 'doctor' => true],
    ])
        ->assertCreated()
        ->assertExactJson(['data' => ['email' => 'anna@example.com', 'status' => 'subscribed', 'created' => true]]);

    $contact = $this->list->contacts()->first();
    expect($contact->email)->toBe('anna@example.com')
        ->and($contact->name)->toBe('Анна')
        ->and($contact->data)->toBe(['city' => 'Москва', 'visits' => '3', 'doctor' => '1'])
        ->and($contact->organization_id)->toBe($this->organization->id)
        ->and($contact->confirmation_token)->toBeNull();

    Queue::assertPushedOn('sender', CheckContactsJob::class, fn ($job) => $job->listId === $this->list->id);
    Queue::assertNotPushed(SendSubscriptionConfirmationsJob::class);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com'])
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.status', 'subscribed');

    expect($this->list->contacts()->count())->toBe(1)
        ->and($this->list->contacts()->first()->name)->toBe('Анна');
    Queue::assertPushed(CheckContactsJob::class, 1);
});

it('creates an unsubscribed contact and resubscribes it later', function (): void {
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'subscribed' => false])
        ->assertCreated()
        ->assertJsonPath('data.status', 'unsubscribed');

    $contact = $this->list->contacts()->first();
    expect($contact->unsubscribed_at)->not->toBeNull();
    $unsubscribedAt = $contact->unsubscribed_at;

    $this->travel(1)->hours();
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'subscribed' => false])
        ->assertOk()->assertJsonPath('data.status', 'unsubscribed');
    expect($contact->refresh()->unsubscribed_at->equalTo($unsubscribedAt))->toBeTrue();

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'subscribed' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'subscribed')
        ->assertJsonPath('data.created', false);

    expect($contact->refresh()->unsubscribed_at)->toBeNull()
        ->and($this->list->contacts()->deliverable()->count())->toBe(1);
    Queue::assertNotPushed(SendSubscriptionConfirmationsJob::class);
});

it('keeps new contacts pending in a list that requires confirmation and queues one letter only', function (): void {
    requireConfirmation($this->list);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $contact = $this->list->contacts()->first();
    expect($contact->isPending())->toBeTrue()
        ->and($contact->confirmation_token)->toHaveLength(48);
    Queue::assertPushedOn('sender', SendSubscriptionConfirmationsJob::class, fn ($job) => $job->listId === $this->list->id);
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);

    // повторная передача того же адреса письма не добавляет
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'name' => 'Анна'])
        ->assertOk()->assertJsonPath('data.status', 'pending');
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);

    // письмо ушло, адрес отписался и снова подписался: ждёт подтверждения, но второе письмо не ставится
    $contact->forceFill(['confirmation_sent_at' => now()])->save();
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'subscribed' => false])
        ->assertOk()->assertJsonPath('data.status', 'unsubscribed');
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'subscribed' => true])
        ->assertOk()->assertJsonPath('data.status', 'pending');

    expect($contact->refresh()->confirmation_token)->not->toBeNull()
        ->and($contact->unsubscribed_at)->toBeNull();
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);
    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => 'Сайт'])
        ->assertOk()
        ->assertJsonPath('data.require_confirmation', true)
        ->assertJsonPath('data.pending_count', 1)
        ->assertJsonPath('data.subscribed_count', 0);
});

it('asks for confirmation when an unconfirmed address resubscribes, and not when it has confirmed', function (): void {
    requireConfirmation($this->list);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'never@example.com', 'subscribed' => false])
        ->assertCreated()->assertJsonPath('data.status', 'unsubscribed');
    Queue::assertNotPushed(SendSubscriptionConfirmationsJob::class);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'never@example.com'])
        ->assertOk()->assertJsonPath('data.status', 'pending');
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);

    $this->list->contacts()->create([
        'organization_id' => $this->organization->id, 'email' => 'confirmed@example.com',
        'confirmation_token' => 'tok', 'confirmed_at' => now(), 'unsubscribed_at' => now(),
    ]);
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'confirmed@example.com'])
        ->assertOk()->assertJsonPath('data.status', 'subscribed');
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);
});

it('keeps suppressed addresses suppressed and sends them no confirmation', function (): void {
    requireConfirmation($this->list);
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'blocked@example.com', 'reason' => Suppression::REASON_COMPLAINT]);

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'blocked@example.com'])
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    Queue::assertNotPushed(SendSubscriptionConfirmationsJob::class);
    expect(Suppression::query()->where('email', 'blocked@example.com')->exists())->toBeTrue()
        ->and($this->list->contacts()->deliverable()->count())->toBe(0);

    $this->list->forceFill(['require_confirmation' => false])->save();
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'blocked2@example.com'])->assertCreated();
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'blocked2@example.com', 'reason' => Suppression::REASON_UNSUBSCRIBE]);
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'blocked2@example.com', 'subscribed' => true])->assertOk();

    expect(Suppression::query()->where('email', 'blocked2@example.com')->exists())->toBeTrue()
        ->and($this->list->contacts()->deliverable()->count())->toBe(0);
});

it('merges data and removes keys passed as null', function (): void {
    $this->withToken($this->key)->putJson(contactsUrl($this->list), [
        'email' => 'anna@example.com', 'name' => 'Анна', 'data' => ['city' => 'Москва', 'specialty' => 'терапевт'],
    ])->assertCreated();

    $this->withToken($this->key)->putJson(contactsUrl($this->list), [
        'email' => 'anna@example.com', 'name' => null, 'data' => ['city' => null, 'clinic' => 'Медцентр', 'paid' => false],
    ])->assertOk();

    $this->withToken($this->key)->getJson(contactsUrl($this->list, '/anna@example.com'))
        ->assertOk()
        ->assertJsonPath('data.email', 'anna@example.com')
        ->assertJsonPath('data.name', 'Анна')
        ->assertJsonPath('data.data', ['specialty' => 'терапевт', 'clinic' => 'Медцентр', 'paid' => '0'])
        ->assertJsonPath('data.status', 'subscribed');

    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'data' => ['specialty' => null, 'clinic' => null, 'paid' => null]])
        ->assertOk();
    expect($this->list->contacts()->first()->data)->toBeNull();
});

it('validates contact fields with russian messages', function (array $payload, string $field): void {
    $this->withToken($this->key)->putJson(contactsUrl($this->list), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Contact::query()->count())->toBe(0);
})->with([
    'no email' => [['name' => 'Анна'], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'long name' => [['email' => 'a@example.com', 'name' => str_repeat('я', 251)], 'name'],
    'uppercase key' => [['email' => 'a@example.com', 'data' => ['City' => 'Москва']], 'data'],
    'key with dash' => [['email' => 'a@example.com', 'data' => ['first-name' => 'Анна']], 'data'],
    'key starts with digit' => [['email' => 'a@example.com', 'data' => ['1city' => 'Москва']], 'data'],
    'list instead of object' => [['email' => 'a@example.com', 'data' => ['Москва']], 'data'],
    'nested value' => [['email' => 'a@example.com', 'data' => ['city' => ['name' => 'Москва']]], 'data.city'],
    'too many keys' => [['email' => 'a@example.com', 'data' => array_combine(array_map(fn ($i) => 'k'.$i, range(1, 51)), range(1, 51))], 'data'],
    'subscribed not boolean' => [['email' => 'a@example.com', 'subscribed' => 'maybe'], 'subscribed'],
]);

it('answers validation errors in json even without the accept header', function (): void {
    $this->withToken($this->key)->put(contactsUrl($this->list), ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Неверный email');
});

it('applies a batch with valid and invalid rows', function (): void {
    requireConfirmation($this->list);
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'old@example.com', 'name' => 'Старый', 'data' => ['city' => 'Казань']]);

    $response = $this->withToken($this->key)->postJson(contactsUrl($this->list, '/batch'), ['contacts' => [
        ['email' => 'new1@example.com', 'name' => 'Новый'],
        ['email' => 'not-an-email'],
        ['email' => 'NEW2@example.com', 'subscribed' => false],
        ['email' => 'old@example.com', 'data' => ['city' => null, 'clinic' => 'Медцентр']],
        ['email' => 'bad@example.com', 'data' => ['Bad-Key' => 1]],
        'строка',
        ['email' => 'new3@example.com'],
        ['email' => 'new3@example.com', 'name' => 'Третий'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('data.created', 3)
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.subscribed', 1)
        ->assertJsonPath('data.pending', 2)
        ->assertJsonPath('data.unsubscribed', 1)
        ->assertJsonCount(3, 'data.invalid')
        ->assertJsonPath('data.invalid.0.index', 1)
        ->assertJsonPath('data.invalid.0.email', 'not-an-email')
        ->assertJsonPath('data.invalid.0.errors.email.0', 'Неверный email')
        ->assertJsonPath('data.invalid.1.index', 4)
        ->assertJsonPath('data.invalid.1.email', 'bad@example.com')
        ->assertJsonPath('data.invalid.2.index', 5)
        ->assertJsonPath('data.invalid.2.email', null);

    expect($this->list->contacts()->where('email', 'old@example.com')->first()->data)->toBe(['clinic' => 'Медцентр'])
        ->and($this->list->contacts()->where('email', 'new3@example.com')->first()->name)->toBe('Третий')
        ->and($this->list->contacts()->where('email', 'new2@example.com')->first()->unsubscribed_at)->not->toBeNull()
        ->and($this->list->contacts()->pending()->count())->toBe(2)
        ->and($this->list->contacts()->count())->toBe(4);

    Queue::assertPushed(CheckContactsJob::class, 1);
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);
});

it('handles a batch of 1000 rows and rejects bigger ones', function (): void {
    $rows = array_map(fn (int $i): array => ['email' => 'user'.$i.'@example.com', 'data' => ['n' => $i]], range(1, 1000));

    $this->withToken($this->key)->postJson(contactsUrl($this->list, '/batch'), ['contacts' => $rows])
        ->assertOk()
        ->assertJsonPath('data.created', 1000)
        ->assertJsonPath('data.subscribed', 1000)
        ->assertJsonPath('data.invalid', []);

    expect($this->list->contacts()->count())->toBe(1000);
    Queue::assertPushed(CheckContactsJob::class, 1);

    $this->withToken($this->key)->postJson(contactsUrl($this->list, '/batch'), ['contacts' => $rows])
        ->assertOk()
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.updated', 1000);

    $this->withToken($this->key)->postJson(contactsUrl($this->list, '/batch'), ['contacts' => [...$rows, ['email' => 'extra@example.com']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contacts' => 'Не больше 1000 подписчиков за один запрос']);
    $this->withToken($this->key)->postJson(contactsUrl($this->list, '/batch'), ['contacts' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('contacts');
});

it('shows and deletes a contact', function (): void {
    $this->withToken($this->key)->putJson(contactsUrl($this->list), ['email' => 'anna@example.com', 'name' => 'Анна'])->assertCreated();

    $this->withToken($this->key)->getJson(contactsUrl($this->list, '/ANNA@example.com'))
        ->assertOk()
        ->assertJsonPath('data.email', 'anna@example.com')
        ->assertJsonPath('data.name', 'Анна')
        ->assertJsonPath('data.data', [])
        ->assertJsonPath('data.status', 'subscribed');

    $this->withToken($this->key)->getJson(contactsUrl($this->list, '/missing@example.com'))
        ->assertNotFound()->assertJsonPath('message', 'Подписчик не найден');

    $this->withToken($this->key)->deleteJson(contactsUrl($this->list, '/anna@example.com'))->assertNoContent();
    $this->withToken($this->key)->deleteJson(contactsUrl($this->list, '/anna@example.com'))->assertNotFound();
    $this->withToken($this->key)->getJson(contactsUrl($this->list, '/anna@example.com'))->assertNotFound();
});

it('refuses writes to a synced list but allows reading it', function (): void {
    $synced = $this->organization->lists()->create(['name' => 'Пользователи сайта', 'source' => 'users']);
    $synced->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com']);
    $message = 'Эта база собирается автоматически, менять её через API нельзя';

    // база из источника не отдаётся по названию: иначе внешняя система писала бы в неё и вечно получала 422
    $this->withToken($this->key)->putJson('/api/sender/v1/lists', ['name' => 'пользователи сайта'])
        ->assertConflict()
        ->assertJsonPath('message', 'База с таким названием собирается автоматически, выберите другое название')
        ->assertJsonMissingPath('data');
    expect($this->organization->lists()->count())->toBe(2);

    $this->withToken($this->key)->putJson(contactsUrl($synced), ['email' => 'petr@example.com'])
        ->assertUnprocessable()->assertJsonPath('message', $message);
    $this->withToken($this->key)->postJson(contactsUrl($synced, '/batch'), ['contacts' => [['email' => 'petr@example.com']]])
        ->assertUnprocessable()->assertJsonPath('message', $message);
    $this->withToken($this->key)->deleteJson(contactsUrl($synced, '/anna@example.com'))
        ->assertUnprocessable()->assertJsonPath('message', $message);
    $this->withToken($this->key)->getJson(contactsUrl($synced, '/anna@example.com'))->assertOk();

    expect($synced->contacts()->count())->toBe(1);
});

it('exposes require_confirmation in the admin api and lets the admin toggle it', function (): void {
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $this->withToken($token)->getJson('/api/sender/v1/admin/lists')
        ->assertOk()->assertJsonPath('data.0.require_confirmation', false);

    $id = $this->withToken($token)->postJson('/api/sender/v1/admin/lists', ['name' => 'Подтверждаемая', 'require_confirmation' => true])
        ->assertCreated()
        ->assertJsonPath('data.require_confirmation', true)
        ->json('data.id');

    $this->withToken($token)->putJson('/api/sender/v1/admin/lists/'.$this->list->id, ['name' => 'Сайт', 'require_confirmation' => true])
        ->assertOk()->assertJsonPath('data.require_confirmation', true);
    $this->withToken($token)->getJson('/api/sender/v1/admin/lists/'.$this->list->id)
        ->assertOk()->assertJsonPath('data.require_confirmation', true);

    $this->withToken($token)->putJson('/api/sender/v1/admin/lists/'.$id, ['name' => 'Подтверждаемая', 'require_confirmation' => false])
        ->assertOk()->assertJsonPath('data.require_confirmation', false);
    $this->withToken($token)->putJson('/api/sender/v1/admin/lists/'.$id, ['name' => 'Подтверждаемая', 'require_confirmation' => 'maybe'])
        ->assertUnprocessable()->assertJsonValidationErrors('require_confirmation');

    // без поля настройка не меняется
    $this->withToken($token)->putJson('/api/sender/v1/admin/lists/'.$this->list->id, ['name' => 'Сайт'])
        ->assertOk()->assertJsonPath('data.require_confirmation', true);
});

it('defaults import consent to the list setting while an explicit consent wins', function (): void {
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
    $import = fn (array $payload) => $this->withToken($token)
        ->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/import', $payload)->assertOk();

    $import(['text' => 'a@example.com'])->assertJsonPath('data.pending', 0);

    requireConfirmation($this->list);
    $import(['text' => 'b@example.com'])->assertJsonPath('data.pending', 1);
    $import(['text' => 'c@example.com', 'consent' => true])->assertJsonPath('data.pending', 0);
    $import(['text' => 'd@example.com', 'consent' => 'false'])->assertJsonPath('data.pending', 1);
});

function contactsAdminToken(Organization $organization): string
{
    User::create(['organization_id' => $organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);

    return (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
}

it('rejects an empty or unreadable column mapping instead of failing', function (mixed $mapping, string $field, string $message): void {
    $token = contactsAdminToken($this->organization);

    $this->withToken($token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/import', ['text' => "email;имя\nanna@example.com;Анна", 'mapping' => $mapping])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect($this->list->contacts()->count())->toBe(0);
})->with([
    'empty object string' => ['{}', 'mapping.email', 'Выберите колонку с адресом почты'],
    'empty array string' => ['[]', 'mapping.email', 'Выберите колонку с адресом почты'],
    'empty array' => [[], 'mapping.email', 'Выберите колонку с адресом почты'],
    'not json' => ['not json', 'mapping', 'Не удалось прочитать сопоставление колонок'],
    'json scalar' => ['5', 'mapping', 'Не удалось прочитать сопоставление колонок'],
]);

it('still imports with a valid mapping passed as a json string', function (): void {
    $token = contactsAdminToken($this->organization);

    $this->withToken($token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/import', [
        'text' => "имя;почта\nАнна;anna@example.com",
        'mapping' => json_encode(['email' => 1, 'name' => 0, 'header' => true]),
    ])->assertOk()->assertJsonPath('data.added', 1);

    expect($this->list->contacts()->value('name'))->toBe('Анна');
});

it('caps the import preview at 200 columns and stays fast on very wide files', function (): void {
    $token = contactsAdminToken($this->organization);
    $rows = collect(range(1, 300))->map(fn (int $i): string => "user{$i}@example.com".str_repeat(';v', 999))->implode("\n");
    $text = str_repeat(';', 1000)."\n".$rows;

    $started = microtime(true);
    $response = $this->withToken($token)->postJson('/api/sender/v1/admin/lists/import-preview', ['text' => $text])
        ->assertOk()
        ->assertJsonPath('data.rows', 300)
        ->assertJsonPath('data.header', true)
        ->assertJsonPath('data.columns_truncated', true)
        ->assertJsonCount(200, 'data.columns')
        ->assertJsonCount(5, 'data.columns.0.samples')
        ->assertJsonPath('data.columns.0.samples.0', 'user1@example.com')
        ->assertJsonPath('data.columns.199.samples.0', 'v')
        ->assertJsonCount(200, 'data.sample_rows.0');

    expect(microtime(true) - $started)->toBeLessThan(3.0)
        ->and(strlen($response->getContent()))->toBeLessThan(200_000);

    $this->withToken($token)->postJson('/api/sender/v1/admin/lists/import-preview', ['text' => "email;имя\nanna@example.com;Анна"])
        ->assertOk()
        ->assertJsonPath('data.columns_truncated', false)
        ->assertJsonCount(2, 'data.columns')
        ->assertJsonPath('data.columns.0.role', 'email')
        ->assertJsonPath('data.columns.1.role', 'name')
        ->assertJsonPath('data.columns.1.samples', ['Анна']);
});
