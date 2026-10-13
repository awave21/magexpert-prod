<?php

use App\Jobs\SyncUserToBitrix24;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const B24 = 'https://crm.test/rest/1/token';

beforeEach(function (): void {
    config(['bitrix24.webhook_url' => B24, 'bitrix24.http.retry_times' => 1]);
    Http::preventStrayRequests();
});

function bitrixUser(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'first_name' => 'Анна', 'last_name' => 'Петрова', 'email' => 'anna@example.com',
        'phone' => '+79990000000', 'city' => 'Москва', 'specialization' => 'Радиология', 'position' => 'Врач',
    ]);
}

it('обновляет существующий контакт в Bitrix24 по данным профиля', function (): void {
    $user = bitrixUser(['city' => 'Казань', 'email' => 'new@example.com']);

    Http::fake([
        B24.'/crm.contact.list.json' => Http::response(['result' => [['ID' => '77']]]),
        B24.'/crm.contact.get.json' => Http::response(['result' => ['ID' => '77', 'EMAIL' => [['ID' => '5', 'VALUE' => 'old@example.com']], 'PHONE' => [['ID' => '6', 'VALUE' => '+7 (999) 000-00-00']]]]),
        B24.'/crm.contact.update.json' => Http::response(['result' => true]),
    ]);

    app()->call([new SyncUserToBitrix24($user->id, 'old@example.com'), 'handle']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/crm.contact.update.json')
        && $request['id'] === 77
        && $request['fields']['NAME'] === 'Анна'
        && $request['fields']['UF_CRM_ITS_DICT_CITY'] === 'Казань'
        && $request['fields']['UF_CRM_1642077639'] === 'Радиология'
        && $request['fields']['EMAIL'] === [['ID' => '5', 'VALUE' => 'new@example.com', 'VALUE_TYPE' => 'WORK']]
        && ! array_key_exists('PHONE', $request['fields']));
});

it('не создаёт контакт, которого нет в Bitrix24', function (): void {
    $user = bitrixUser();

    Http::fake([B24.'/crm.contact.list.json' => Http::response(['result' => []])]);

    app()->call([new SyncUserToBitrix24($user->id), 'handle']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'contact.add') || str_contains($request->url(), 'contact.update'));
});

it('ставит задачу только при изменении полей профиля и включённой интеграции', function (): void {
    Queue::fake();
    $user = bitrixUser();
    Queue::assertPushed(SyncUserToBitrix24::class, 0);

    $user->update(['city' => 'Сочи']);
    Queue::assertPushed(SyncUserToBitrix24::class, fn (SyncUserToBitrix24 $job) => $job->userId === $user->id && $job->previousEmail === null);

    Queue::fake();
    $user->update(['remember_token' => 'x']);
    Queue::assertNothingPushed();

    config(['bitrix24.webhook_url' => '']);
    $user->update(['city' => 'Тверь']);
    Queue::assertNothingPushed();
});

it('передаёт прежний адрес при смене email', function (): void {
    Queue::fake();
    $user = bitrixUser();

    $user->update(['email' => 'changed@example.com']);

    Queue::assertPushed(SyncUserToBitrix24::class, fn (SyncUserToBitrix24 $job) => $job->previousEmail === 'anna@example.com');
});
