<?php

use App\Mcp\McpTokens;
use App\Models\Category;
use App\Models\Event;
use App\Models\McpAuditLog;
use App\Models\Partner;
use App\Models\Role;
use App\Models\Speaker;
use App\Models\User;

function siteMcpUser(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

    return [$user, app(McpTokens::class)->issue($user, 'тест')['plain']];
}

function siteMcpCall(string $key, string $tool, array $arguments = []): \Illuminate\Testing\TestResponse
{
    return test()->withToken($key)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function siteEventArgs(array $extra = []): array
{
    return array_merge(['title' => 'Лютеиновая фаза', 'event_type' => 'webinar', 'start_date' => '2026-12-01'], $extra);
}

test('без ключа и с чужим ключом сервер не пускает', function () {
    $this->postJson('/api/mcp', [])->assertUnauthorized();
    $this->withToken('mxe_wrong')->postJson('/api/mcp', [])->assertUnauthorized();
});

test('обычный пользователь не получает доступ по ключу', function () {
    [, $key] = siteMcpUser('user');
    $this->withToken($key)->postJson('/api/mcp', [])->assertUnauthorized();
});

test('сервер отдаёт список инструментов', function () {
    [, $key] = siteMcpUser();
    $names = collect($this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name');

    expect($names)->toContain('create_event', 'publish_event', 'set_event_speakers', 'list_categories', 'delete_speaker', 'update_library', 'list_library');
});

test('мероприятие создаётся, получает адрес и теги, публикуется и скрывается', function () {
    [, $key] = siteMcpUser('editor');

    $item = siteMcpCall($key, 'create_event', siteEventArgs(['city' => 'Москва']))->assertOk()->json('result.structuredContent.item');

    expect($item['slug'])->toBe('liuteinovaia-faza')
        ->and($item['is_active'])->toBeFalse()
        ->and($item['tags'])->not->toBeEmpty();

    siteMcpCall($key, 'publish_event', ['id' => $item['id']])->assertJsonPath('result.structuredContent.is_active', true);
    expect(Event::find($item['id'])->is_active)->toBeTrue();

    siteMcpCall($key, 'unpublish_event', ['id' => $item['id']]);
    expect(Event::find($item['id'])->is_active)->toBeFalse();
    expect(McpAuditLog::query()->where('action', 'publish')->count())->toBe(1);
});

test('правила мероприятия: платное без цены и дата окончания раньше начала отклоняются', function () {
    [, $key] = siteMcpUser();

    siteMcpCall($key, 'create_event', siteEventArgs(['is_paid' => true]))->assertJsonPath('result.isError', true);
    siteMcpCall($key, 'create_event', siteEventArgs(['end_date' => '2026-11-01']))->assertJsonPath('result.isError', true);
    siteMcpCall($key, 'create_event', ['title' => 'Без даты', 'event_type' => 'webinar'])->assertJsonPath('result.isError', true);
    siteMcpCall($key, 'create_event', ['title' => 'Запись', 'event_type' => 'webinar', 'is_on_demand' => true])->assertJsonPath('result.isError', false);
    expect(Event::count())->toBe(1);
});

test('неизвестное поле и неверный тип отклоняются схемой', function () {
    [, $key] = siteMcpUser();

    $this->withToken($key)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'create_event', 'arguments' => ['title' => 'x', 'event_type' => 'party', 'hack' => 1]],
    ])->assertJsonPath('error.code', -32602);
});

test('изменение меняет только переданные поля', function () {
    [, $key] = siteMcpUser();
    $event = Event::factory()->create(['title' => 'Старое', 'city' => 'Казань', 'start_date' => '2026-12-01', 'event_type' => 'webinar']);

    siteMcpCall($key, 'update_event', ['id' => $event->id, 'title' => 'Новое'])->assertJsonPath('result.isError', false);

    expect($event->fresh()->title)->toBe('Новое')->and($event->fresh()->city)->toBe('Казань');
});

test('спикеры привязываются к мероприятию с ролью и темой', function () {
    [, $key] = siteMcpUser();
    $event = Event::factory()->create();
    $a = Speaker::factory()->create();
    $b = Speaker::factory()->create();

    siteMcpCall($key, 'set_event_speakers', ['event_id' => $event->id, 'speakers' => [
        ['id' => $b->id, 'role' => 'Лектор', 'topic' => 'Тема'],
        ['id' => $a->id],
    ]])->assertJsonPath('result.isError', false);

    $speakers = $event->speakers()->orderBy('event_speaker.sort_order')->get();
    expect($speakers->pluck('id')->all())->toBe([$b->id, $a->id])->and($speakers[0]->pivot->role)->toBe('Лектор');

    siteMcpCall($key, 'set_event_speakers', ['event_id' => $event->id, 'speakers' => [['id' => 99999]]])->assertJsonPath('result.isError', true);
    siteMcpCall($key, 'set_event_speakers', ['event_id' => $event->id, 'speakers' => []]);
    expect($event->speakers()->count())->toBe(0);
});

test('спикер создаётся и ищется по фамилии в любом регистре', function () {
    [, $key] = siteMcpUser();

    siteMcpCall($key, 'create_speaker', ['last_name' => 'Иванова', 'first_name' => 'Анна'])->assertJsonPath('result.isError', false);

    expect(siteMcpCall($key, 'list_speakers', ['q' => 'иванова'])->json('result.structuredContent.total'))->toBe(1);
});

test('удаление требует подтверждения и роли admin или manager', function () {
    $speaker = Speaker::factory()->create();
    [, $editorKey] = siteMcpUser('editor');
    [, $adminKey] = siteMcpUser('admin');

    siteMcpCall($adminKey, 'delete_speaker', ['id' => $speaker->id, 'confirm' => false])->assertJsonPath('result.isError', true);
    siteMcpCall($editorKey, 'delete_speaker', ['id' => $speaker->id, 'confirm' => true])->assertJsonPath('result.isError', true);
    expect(Speaker::find($speaker->id))->not->toBeNull();

    siteMcpCall($adminKey, 'delete_speaker', ['id' => $speaker->id, 'confirm' => true])->assertJsonPath('result.structuredContent.deleted', true);
    expect(Speaker::find($speaker->id))->toBeNull()
        ->and(McpAuditLog::query()->where('action', 'delete')->exists())->toBeTrue();
});

test('категорию с мероприятиями удалить нельзя', function () {
    [, $key] = siteMcpUser();
    $category = Category::factory()->create();
    $event = Event::factory()->create();
    $event->categories()->attach($category->id);
    $event->update(['category_id' => $category->id]);

    siteMcpCall($key, 'delete_category', ['id' => $category->id, 'confirm' => true])->assertJsonPath('result.isError', true);
    expect(Category::find($category->id))->not->toBeNull();
});

test('категория создаётся с адресом из названия, второй раз — с номером', function () {
    [, $key] = siteMcpUser();

    siteMcpCall($key, 'create_category', ['name' => 'Гинекология']);
    $second = siteMcpCall($key, 'create_category', ['name' => 'Гинекология'])->json('result.structuredContent.item');

    expect($second['slug'])->toBe('ginekologiia-2');
});

test('партнёра и материал библиотеки нельзя создать без файла, но можно править', function () {
    [, $key] = siteMcpUser();

    siteMcpCall($key, 'create_partner', ['name' => 'X'])->assertJsonPath('result.isError', true);

    $partner = Partner::factory()->create(['name' => 'Старое']);
    siteMcpCall($key, 'update_partner', ['id' => $partner->id, 'name' => 'Новое'])->assertJsonPath('result.isError', false);
    expect($partner->fresh()->name)->toBe('Новое');
});

test('отозванный ключ перестаёт работать', function () {
    [$user, $key] = siteMcpUser();
    \App\Models\McpToken::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);

    $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
});
