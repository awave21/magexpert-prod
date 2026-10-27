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

test('отозванный ключ перестаёт работать', function () {
    [$user, $key] = siteMcpUser();
    \App\Models\McpToken::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);

    $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
});

test('в админке администратор создаёт и отзывает ключ, ключ показывается один раз', function () {
    [$admin] = siteMcpUser('admin');

    $response = $this->actingAs($admin)->post(route('admin.mcp.keys.store'), ['name' => 'Ноутбук'])->assertOk();
    $plain = $response->viewData('page')['props']['newKey'];

    expect($plain)->toStartWith('mxe_');
    $this->withToken($plain)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertOk();

    $this->actingAs($admin)->get(route('admin.mcp'))->assertOk()->assertDontSee($plain, false);

    $token = \App\Models\McpToken::query()->where('name', 'Ноутбук')->first();
    $this->actingAs($admin)->delete(route('admin.mcp.keys.destroy', $token))->assertRedirect();
    $this->withToken($plain)->postJson('/api/mcp', [])->assertUnauthorized();
});

test('редактору страница ключей недоступна', function () {
    [$editor] = siteMcpUser('editor');

    $this->actingAs($editor)->get(route('admin.mcp'))->assertForbidden();
});

test('файл обложки по умолчанию на месте', function () {
    expect(public_path('images/default-event-cover.webp'))->toBeFile();
});

function sitePng(): string
{
    ob_start();
    $img = imagecreatetruecolor(8, 8);
    imagepng($img);

    return base64_encode((string) ob_get_clean());
}

test('обложка, фото спикера и логотип загружаются из base64 и убираются', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    [, $key] = siteMcpUser();
    $event = Event::factory()->create(['image' => null]);
    $speaker = Speaker::factory()->create();

    siteMcpCall($key, 'set_image', ['target' => 'event_cover', 'id' => $event->id, 'source' => ['base64' => sitePng()]])->assertJsonPath('result.isError', false);
    expect($event->fresh()->image)->toStartWith('/storage/events/'.$event->id.'/');
    \Illuminate\Support\Facades\Storage::disk('public')->assertExists(str_replace('/storage/', '', $event->fresh()->image));

    siteMcpCall($key, 'set_image', ['target' => 'speaker_photo', 'id' => $speaker->id, 'source' => ['base64' => 'data:image/png;base64,'.sitePng()]])->assertJsonPath('result.isError', false);
    expect($speaker->fresh()->photo)->toStartWith('/storage/speakers/');

    siteMcpCall($key, 'remove_image', ['target' => 'event_cover', 'id' => $event->id])->assertJsonPath('result.structuredContent.removed', true);
    expect($event->fresh()->image)->toBeNull();
});

test('не картинка, пустой источник, http и закрытые адреса отклоняются', function () {
    [, $key] = siteMcpUser();
    $event = Event::factory()->create();

    foreach ([
        ['base64' => base64_encode('это текст')],
        [],
        ['url' => 'http://example.com/a.png'],
        ['url' => 'https://127.0.0.1/a.png'],
        ['url' => 'https://192.168.1.5/a.png'],
        ['url' => 'https://example.com/a.png', 'base64' => sitePng()],
    ] as $source) {
        siteMcpCall($key, 'set_image', ['target' => 'event_cover', 'id' => $event->id, 'source' => (object) $source])->assertJsonPath('result.isError', true);
    }
});

test('партнёр создаётся только с логотипом, материал — с документом', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    [, $key] = siteMcpUser();

    siteMcpCall($key, 'create_partner', ['name' => 'Без логотипа'])->assertJsonPath('error.code', -32602);
    expect(Partner::count())->toBe(0);

    siteMcpCall($key, 'create_partner', ['name' => 'Партнёр', 'logo' => ['base64' => sitePng()]])->assertJsonPath('result.isError', false);
    expect(Partner::first()->logo_path)->toStartWith('partners/');

    siteMcpCall($key, 'create_partner', ['name' => 'Битый', 'logo' => ['base64' => base64_encode('текст')]])->assertJsonPath('result.isError', true);
    expect(Partner::count())->toBe(1);

    siteMcpCall($key, 'create_library', [
        'title' => 'Протокол', 'publication_date' => '2026-10-01', 'language' => 'ru',
        'file' => ['base64' => base64_encode('%PDF-1.4 test'), 'filename' => 'protocol.pdf'],
    ])->assertJsonPath('result.isError', false);
    $item = \App\Models\MedicalLibrary::first();
    expect($item->file_path)->toEndWith('.pdf');
    \Illuminate\Support\Facades\Storage::disk('public')->assertExists($item->file_path);

    siteMcpCall($key, 'set_library_file', ['id' => $item->id, 'source' => ['base64' => base64_encode('x'), 'filename' => 'x.exe']])->assertJsonPath('result.isError', true);
});
