<?php

use App\Jobs\SyncUserToSender;
use App\Models\User;
use App\Sender\Client\HttpContactSyncClient;
use App\Sender\Client\LogContactSyncClient;
use App\Sender\Client\SenderApiException;
use App\Sender\Contracts\ContactSyncClient;
use App\Sender\Events\RecipientUnsubscribed;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const SENDER_API = 'https://sender.test/api/sender/v1';

beforeEach(function () {
    config([
        'sender.client.driver' => 'http',
        'sender.client.url' => 'https://sender.test',
        'sender.client.api_key' => 'test-key',
        'sender.client.users_list_name' => 'Пользователи MagExpert',
        'sender.client.users_list_id' => null,
        'sender.client.users_sync' => true,
    ]);

    Sleep::fake();
    Http::preventStrayRequests();
});

/**
 * Ответы Sender API по умолчанию; $overrides — свои ответы для отдельных адресов.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeSenderApi(array $overrides = []): void
{
    Http::fake($overrides + [
        SENDER_API.'/lists' => Http::response(['data' => ['id' => 7, 'name' => 'Пользователи MagExpert', 'require_confirmation' => false]], 201),
        SENDER_API.'/lists/*/contacts/batch' => Http::response(['data' => ['created' => 1, 'updated' => 0, 'subscribed' => 1, 'pending' => 0, 'unsubscribed' => 0, 'invalid' => []]]),
        SENDER_API.'/lists/*/contacts/*' => Http::response(null, 204),
        SENDER_API.'/lists/*/contacts' => fn (Request $request) => Http::response(['data' => ['email' => $request['email'], 'status' => $request['subscribed'] ? 'subscribed' : 'unsubscribed', 'created' => true]], 201),
        SENDER_API.'/subscriptions' => Http::response(['data' => []]),
    ]);
}

/**
 * Новая подделка очереди и снятие блокировок уникальности: в настоящей очереди блокировка снимается,
 * как только задача взята в работу, а в подделке задачи не выполняются.
 */
function resetQueue(): void
{
    Queue::fake();
    User::query()->pluck('id')->each(fn (int $id) => Cache::lock('laravel_unique_job:'.SyncUserToSender::class.':'.$id)->forceRelease());
}

function runPushedSyncJobs(): void
{
    Queue::pushed(SyncUserToSender::class)->each(fn (SyncUserToSender $job) => app()->call([$job, 'handle']));
}

function userWithProfile(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'first_name' => 'Анна',
        'last_name' => 'Петрова',
        'middle_name' => 'Ивановна',
        'email' => 'Anna@Example.com',
        'phone' => '+79990000000',
        'city' => 'Москва',
        'specialization' => 'Радиология',
        'company' => 'Клиника',
        'position' => 'Врач',
        'newsletter_consent' => true,
    ]);
}

it('собирает контакт без телефона, пароля и токенов', function () {
    Queue::fake();
    $user = userWithProfile();

    $payload = SyncUserToSender::payload($user);

    expect($payload)->toBe([
        'email' => 'anna@example.com',
        'name' => 'Петрова Анна Ивановна',
        'data' => [
            'first_name' => 'Анна',
            'city' => 'Москва',
            'specialization' => 'Радиология',
            'company' => 'Клиника',
            'position' => 'Врач',
            'registered_at' => $user->created_at->format('Y-m-d'),
            'source' => 'magexpert',
        ],
        'subscribed' => true,
    ]);

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($encoded)->not->toContain('79990000000')
        ->and($encoded)->not->toContain('password')
        ->and($encoded)->not->toContain('token');
});

it('ставит задачу при создании пользователя и передаёт контакт в базу', function () {
    Queue::fake();
    fakeSenderApi();

    $user = userWithProfile();

    Queue::assertPushed(SyncUserToSender::class, fn (SyncUserToSender $job) => $job->userId === $user->id && $job->previousEmail === null);

    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === SENDER_API.'/lists'
        && $request['name'] === 'Пользователи MagExpert'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === SENDER_API.'/lists/7/contacts'
        && $request['email'] === 'anna@example.com'
        && $request['subscribed'] === true
        && ! array_key_exists('phone', $request->data()['data']));

    expect(Cache::get(SyncUserToSender::listCacheKey()))->toBe(7);
});

it('ставит задачу только при изменении передаваемых полей', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();

    $user->update(['phone' => '+79991112233']);
    $user->forceFill(['site_notifications' => false])->save();
    Queue::assertNothingPushed();

    $user->update(['city' => 'Казань']);
    Queue::assertPushed(SyncUserToSender::class, 1);
});

it('ничего не ставит в очередь, пока синхронизация выключена', function () {
    config(['sender.client.users_sync' => false]);
    Queue::fake();
    fakeSenderApi();

    $user = userWithProfile();
    $user->update(['city' => 'Казань', 'newsletter_consent' => false]);
    $user->delete();

    Queue::assertNothingPushed();
});

it('при смене адреса удаляет старый контакт и добавляет новый', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();
    fakeSenderApi();

    $user->update(['email' => 'new@example.com']);

    Queue::assertPushed(SyncUserToSender::class, fn (SyncUserToSender $job) => $job->previousEmail === 'Anna@Example.com');

    runPushedSyncJobs();

    $calls = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url())
        ->reject(fn (string $call) => str_ends_with($call, '/lists'))
        ->values()
        ->all();

    expect($calls)->toBe([
        'DELETE '.SENDER_API.'/lists/7/contacts/anna%40example.com',
        'PUT '.SENDER_API.'/lists/7/contacts',
    ]);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && ($request['email'] ?? null) === 'new@example.com');
});

it('удаляет контакт, когда пользователя удалили', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();
    fakeSenderApi();

    $user->delete();
    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === SENDER_API.'/lists/7/contacts/anna%40example.com');
    Http::assertNotSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts');
});

it('снятая галочка рассылки передаётся как отписка', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();
    fakeSenderApi();

    $user->update(['newsletter_consent' => false]);
    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts' && $request['subscribed'] === false);
    // прежняя передача согласия (PUT subscriptions) по-прежнему работает
    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/subscriptions' && $request['subscribed'] === false);
});

it('отписка по ссылке снимает галочку и обновляет базу без повторной отписки', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();
    fakeSenderApi();

    RecipientUnsubscribed::dispatch((string) config('sender.client.organization'), 'anna@example.com');

    expect($user->fresh()->newsletter_consent)->toBeFalse();
    Queue::assertPushed(SyncUserToSender::class, 1);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/subscriptions'));

    runPushedSyncJobs();
    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts' && $request['subscribed'] === false);
});

it('не передаёт обезличенный аккаунт, а старый адрес удаляет', function () {
    Queue::fake();
    $user = userWithProfile();
    resetQueue();
    fakeSenderApi();

    $user->forceFill(['email' => 'deleted-'.$user->id.'@mag-expert.invalid', 'newsletter_consent' => false])->save();
    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/contacts/anna%40example.com'));
    Http::assertNotSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts');
});

it('настроена на повторы с нарастающей паузой и схлопывание по пользователю', function () {
    $job = new SyncUserToSender(5, 'a@example.com');

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900, 3600])
        ->and($job->uniqueFor)->toBe(30)
        ->and($job->uniqueId())->toBe('5')
        ->and($job->afterCommit)->toBeTrue()
        ->and(new SyncUserToSender(5, 'b@example.com', 'A@example.com'))->uniqueId()->toBe('5:a@example.com');
});

it('при 404 забывает ID базы, находит её заново и повторяет один раз', function () {
    Queue::fake();
    $user = userWithProfile();
    Cache::forever(SyncUserToSender::listCacheKey(), 3);

    fakeSenderApi([
        SENDER_API.'/lists/3/contacts' => Http::response(['message' => 'Список не найден'], 404),
    ]);

    app()->call([new SyncUserToSender($user->id, $user->email), 'handle']);

    expect(Cache::get(SyncUserToSender::listCacheKey()))->toBe(7);
    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/lists');
    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts');
});

it('при ошибке данных пишет предупреждение и не повторяет', function () {
    Queue::fake();
    $user = userWithProfile();
    Log::spy();

    fakeSenderApi([
        SENDER_API.'/lists/*/contacts' => Http::response(['message' => 'invalid', 'errors' => ['email' => ['Неверный адрес']]], 422),
    ]);

    app()->call([new SyncUserToSender($user->id, $user->email), 'handle']);

    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'не приняла'))->once();
});

it('повторяет запрос при 5xx и 429, потом отдаёт ошибку очереди', function () {
    Queue::fake();
    $user = userWithProfile();
    config(['sender.client.users_list_id' => 7]);

    Http::fake([
        SENDER_API.'/lists/7/contacts' => Http::sequence()
            ->push(['message' => 'busy'], 429, ['Retry-After' => '2'])
            ->push(['message' => 'error'], 503)
            ->push(['message' => 'error'], 502),
    ]);

    expect(fn () => app()->call([new SyncUserToSender($user->id, $user->email), 'handle']))
        ->toThrow(fn (SenderApiException $exception) => expect($exception->status)->toBe(502)->and($exception->retryable)->toBeTrue());

    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 2000, 1);
});

it('не повторяет запрос при неверном ключе', function () {
    Http::fake([SENDER_API.'/lists' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(ContactSyncClient::class)->ensureList('Пользователи MagExpert'))
        ->toThrow(SenderApiException::class, 'API-ключ');

    Http::assertSentCount(1);
});

it('драйвер local без адреса API только пишет в лог', function () {
    config(['sender.client.driver' => 'local', 'sender.client.url' => null, 'sender.client.api_key' => null]);

    expect(app(ContactSyncClient::class))->toBeInstanceOf(LogContactSyncClient::class);

    config(['sender.client.url' => 'https://sender.test', 'sender.client.api_key' => 'k']);
    expect(app(ContactSyncClient::class))->toBeInstanceOf(HttpContactSyncClient::class);
});

it('принимает адрес API и как адрес сервера, и как полный адрес', function () {
    expect(HttpContactSyncClient::baseUrl())->toBe(SENDER_API);

    config(['sender.client.url' => SENDER_API.'/']);
    expect(HttpContactSyncClient::baseUrl())->toBe(SENDER_API);
});

it('sender:sync-users --dry-run считает пользователей и ничего не отправляет', function () {
    config(['sender.client.users_sync' => false]);
    userWithProfile();
    User::factory()->create(['email' => 'second@example.com', 'newsletter_consent' => false]);
    Http::fake();

    $this->artisan('sender:sync-users', ['--dry-run' => true])
        ->expectsOutputToContain('Проверка без отправки')
        ->expectsOutputToContain('anna@example.com')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('sender:sync-users отправляет всех пакетами и печатает итоги', function () {
    config(['sender.client.users_sync' => false]);
    userWithProfile();
    User::factory()->create(['email' => 'second@example.com', 'newsletter_consent' => false]);
    User::factory()->create(['email' => 'third@example.com', 'newsletter_consent' => false]);
    User::factory()->create(['email' => 'deleted-1@mag-expert.invalid']);

    fakeSenderApi([
        SENDER_API.'/lists/*/contacts/batch' => Http::sequence()
            ->push(['data' => ['created' => 2, 'updated' => 0, 'subscribed' => 1, 'pending' => 0, 'unsubscribed' => 1, 'invalid' => []]])
            ->push(['data' => ['created' => 0, 'updated' => 0, 'subscribed' => 0, 'pending' => 0, 'unsubscribed' => 0, 'invalid' => [['index' => 0, 'email' => 'third@example.com', 'errors' => ['email' => ['Адрес не принимает почту']]]]]]),
    ]);

    $this->artisan('sender:sync-users', ['--chunk' => 2])
        ->expectsOutputToContain('пользователей с адресом: 3')
        ->expectsOutputToContain('Адрес не принимает почту')
        ->assertSuccessful();

    $batches = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => str_ends_with($request->url(), '/batch'))->values();

    expect($batches)->toHaveCount(2)
        ->and(collect($batches[0]['contacts'])->pluck('email')->all())->toBe(['anna@example.com', 'second@example.com'])
        ->and($batches[0]['contacts'][1]['subscribed'])->toBeFalse()
        ->and(collect($batches[1]['contacts'])->pluck('email')->all())->toBe(['third@example.com']);
});

it('sender:sync-users без настроек API сообщает об ошибке', function () {
    config(['sender.client.driver' => 'local', 'sender.client.url' => null, 'sender.client.api_key' => null]);
    Http::fake();

    $this->artisan('sender:sync-users')
        ->expectsOutputToContain('Sender API не настроен')
        ->assertFailed();

    Http::assertNothingSent();
});

it('падает без повторов, если название занято базой, которую Sender собирает сам', function () {
    Queue::fake();
    $user = userWithProfile();

    fakeSenderApi([
        SENDER_API.'/lists' => Http::response(['message' => 'База с таким названием собирается автоматически, выберите другое название'], 409),
    ]);

    $job = (new SyncUserToSender($user->id, $user->email))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertFailed();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/contacts'));
    expect(Cache::get(SyncUserToSender::listCacheKey()))->toBeNull();
});

it('sender:sync-users сообщает, что название базы занято автоматической базой', function () {
    config(['sender.client.users_sync' => false]);
    userWithProfile();

    fakeSenderApi([
        SENDER_API.'/lists' => Http::response(['message' => 'База с таким названием собирается автоматически, выберите другое название'], 409),
    ]);

    $this->artisan('sender:sync-users')
        ->expectsOutputToContain('собирается автоматически')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/contacts'));
});

it('заглушка не пишет адреса пользователей в лог', function () {
    $logged = collect();
    Log::listen(fn (MessageLogged $event) => $logged->push($event));
    $client = new LogContactSyncClient;

    $client->upsertContact(0, 'anna@example.com', 'Анна', ['city' => 'Москва'], true);
    $client->upsertContacts(0, [['email' => 'anna@example.com', 'subscribed' => true]]);
    $client->deleteContact(0, 'anna@example.com');

    expect($logged)->toHaveCount(3)
        ->and($logged->filter(fn (MessageLogged $event) => str_contains($event->message.json_encode($event->context), 'anna')))->toBeEmpty()
        ->and($logged[0]->context)->toBe(['list_id' => 0, 'subscribed' => true])
        ->and($logged[1]->context)->toBe(['list_id' => 0, 'count' => 1]);
});

it('не ставит задачи без адреса и ключа API и один раз предупреждает в логе', function () {
    config(['sender.client.driver' => 'local', 'sender.client.url' => null, 'sender.client.api_key' => null]);
    Queue::fake();
    $logged = collect();
    Log::listen(fn (MessageLogged $event) => $logged->push($event));

    $user = userWithProfile();
    $user->update(['city' => 'Казань', 'email' => 'new@example.com']);
    User::factory()->create(['email' => 'second@example.com']);
    $user->delete();

    Queue::assertNotPushed(SyncUserToSender::class);

    $warnings = $logged->filter(fn (MessageLogged $event) => $event->level === 'warning')->values();
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('SENDER_USERS_SYNC')
        ->and($warnings[0]->context)->toBe([])
        ->and($warnings[0]->message)->not->toContain('@');
});

it('передаёт тег мероприятия вместе с контактом и не затирает подписку', function () {
    Queue::fake();
    $user = userWithProfile(['newsletter_consent' => false]);
    resetQueue();
    fakeSenderApi();

    SyncUserToSender::dispatchEventTag($user, 63);

    Queue::assertPushed(SyncUserToSender::class, fn (SyncUserToSender $job) => $job->tags === ['event-63']);

    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === SENDER_API.'/lists/7/contacts'
        && $request['tags'] === ['event-63']
        && $request['subscribed'] === false);
});

it('не ставит тег мероприятия, когда синхронизация выключена', function () {
    config(['sender.client.users_sync' => false]);
    Queue::fake();
    $user = userWithProfile();
    resetQueue();

    SyncUserToSender::dispatchEventTag($user, 63);

    Queue::assertNotPushed(SyncUserToSender::class);
});

it('ставит согласие на рассылку при регистрации на мероприятие и сразу передаёт его в Sender', function () {
    Queue::fake();
    $user = userWithProfile(['newsletter_consent' => false]);
    resetQueue();
    fakeSenderApi();

    expect($user->assumeNewsletterConsent())->toBeTrue();
    SyncUserToSender::dispatchEventTag($user, 63);

    expect($user->fresh()->newsletter_consent)->toBeTrue()
        ->and($user->fresh()->newsletter_withdrawn_at)->toBeNull();

    runPushedSyncJobs();

    Http::assertSent(fn (Request $request) => $request->url() === SENDER_API.'/lists/7/contacts'
        && $request['subscribed'] === true
        && ($request->data()['tags'] ?? null) === ['event-63']);
});

it('не возвращает согласие тому, кто сам его отозвал', function () {
    Queue::fake();
    $user = userWithProfile(['newsletter_consent' => true]);

    $user->update(['newsletter_consent' => false]);

    expect($user->fresh()->newsletter_withdrawn_at)->not->toBeNull()
        ->and($user->fresh()->assumeNewsletterConsent())->toBeFalse()
        ->and($user->fresh()->newsletter_consent)->toBeFalse();

    // осознанно включил снова: отметка об отзыве снимается
    $user->update(['newsletter_consent' => true]);
    expect($user->fresh()->newsletter_withdrawn_at)->toBeNull();
});
