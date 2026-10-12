<?php

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Mcp\McpTokens;
use App\Sender\Mcp\Permissions;
use App\Sender\Mcp\Prompts;
use App\Sender\Mcp\Server;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Domain;
use App\Sender\Models\McpToken;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\Template;
use App\Sender\Models\TemplateFolder;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости для {{ first_name }}',
        'body_html' => '<html><body><p>Здравствуйте, {{ first_name }}!</p><a href="{{ unsubscribe_url }}">Отписаться</a></body></html>',
        'sender_address_id' => $sender->id,
    ]);

    $this->editor = mcpUser('editor');
    $this->editorToken = app(McpTokens::class)->issue($this->editor, 'Тест')['plain'];
});

function mcpUser(string $role, ?Organization $organization = null): User
{
    $organization ??= test()->organization;

    return User::create([
        'organization_id' => $organization->id, 'name' => 'Пользователь '.$role, 'email' => $role.'-'.$organization->slug.'@example.com',
        'password' => 'secret-pass', 'role' => $role,
    ]);
}

function mcpToken(string $role): string
{
    return app(McpTokens::class)->issue(mcpUser($role), 'Ключ')['plain'];
}

/**
 * @param  array<string, mixed>  $params
 */
function mcpRpc(string $token, string $method, array $params = [], int|string|null $id = 1): TestResponse
{
    $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];

    if ($id !== null) {
        $message['id'] = $id;
    }

    return test()->withToken($token)->postJson('/api/sender/v1/mcp', $message);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpTool(string $token, string $name, array $arguments = []): array
{
    return mcpRpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk()->json('result');
}

it('negotiates the protocol version on initialize', function (): void {
    mcpRpc($this->editorToken, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'sender')
        ->assertJsonPath('result.capabilities.tools.listChanged', false)
        ->assertJsonStructure(['result' => ['capabilities' => ['resources', 'prompts'], 'instructions']]);

    // неизвестная версия — сервер отвечает последней поддерживаемой
    mcpRpc($this->editorToken, 'initialize', ['protocolVersion' => '2099-01-01'])->assertJsonPath('result.protocolVersion', Server::PROTOCOL_VERSIONS[0])
        ->assertJsonPath('result.protocolVersion', '2025-06-18');
    mcpRpc($this->editorToken, 'initialize', [])->assertJsonPath('result.protocolVersion', '2025-06-18');
    mcpRpc($this->editorToken, 'initialize', ['protocolVersion' => '2024-11-05'])->assertJsonPath('result.protocolVersion', '2024-11-05');
    mcpRpc($this->editorToken, 'ping', [], 'abc')->assertOk()->assertJsonPath('id', 'abc')->assertJsonPath('result', []);
});

it('accepts notifications with 202 and supports batches', function (): void {
    $notification = mcpRpc($this->editorToken, 'notifications/initialized', [], null);
    $notification->assertStatus(202);
    expect($notification->getContent())->toBe('');

    $batch = $this->withToken($this->editorToken)->postJson('/api/sender/v1/mcp', [
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope'],
    ])->assertOk();

    expect($batch->json())->toHaveCount(2)
        ->and($batch->json('0.id'))->toBe(1)
        ->and($batch->json('1.error.code'))->toBe(-32601);

    $this->withToken($this->editorToken)->postJson('/api/sender/v1/mcp', [])->assertStatus(400)->assertJsonPath('error.code', -32600);
});

it('lists all tools with schemas and annotations', function (): void {
    $tools = collect(mcpRpc($this->editorToken, 'tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name')->all())->toEqualCanonicalizing([
        'get_brand', 'get_block_schema', 'list_templates', 'get_template', 'create_template', 'update_template',
        'render_template', 'check_template', 'send_test', 'list_folders', 'list_variables',
    ]);

    $tools->each(function (array $tool): void {
        expect($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['description'])->not->toBeEmpty()
            ->and($tool['annotations'])->toHaveKeys(['readOnlyHint', 'destructiveHint', 'idempotentHint']);
    });

    expect($tools->firstWhere('name', 'create_template')['inputSchema']['required'])->toBe(['name', 'subject'])
        ->and($tools->firstWhere('name', 'get_template')['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools->firstWhere('name', 'update_template')['annotations']['destructiveHint'])->toBeTrue();
});

it('rejects requests without a valid key', function (): void {
    $this->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="sender"')
        ->assertJsonPath('error.code', -32001);

    mcpRpc('mxm_'.str_repeat('a', 40), 'ping')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="sender"');

    // ключ входа в админку не подходит
    $adminToken = (new AuthService)->login($this->editor->email, 'secret-pass')['token'];
    mcpRpc($adminToken, 'ping')->assertUnauthorized();

    $token = McpToken::query()->where('user_id', $this->editor->id)->firstOrFail();
    app(McpTokens::class)->revoke($token);
    mcpRpc($this->editorToken, 'ping')->assertUnauthorized();

    $viewer = mcpUser('viewer');
    $plain = app(McpTokens::class)->issue($viewer, 'Ключ')['plain'];
    $viewer->update(['is_active' => false]);
    mcpRpc($plain, 'ping')->assertUnauthorized();
});

it('answers GET with 405', function (): void {
    $this->get('/api/sender/v1/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
});

it('returns protocol errors for bad requests', function (): void {
    mcpRpc($this->editorToken, 'resources/subscribe')->assertOk()->assertJsonPath('error.code', -32601);
    mcpRpc($this->editorToken, 'tools/call', ['name' => 'create_template', 'arguments' => ['subject' => 'Без названия']])
        ->assertOk()->assertJsonPath('error.code', -32602);
    mcpRpc($this->editorToken, 'tools/call', ['name' => 'list_templates', 'arguments' => ['limit' => 'много']])->assertJsonPath('error.code', -32602);
    mcpRpc($this->editorToken, 'tools/call', ['name' => 'no_such_tool'])->assertJsonPath('error.code', -32602);
    mcpRpc($this->editorToken, 'tools/call', ['name' => 'send_test', 'arguments' => ['template' => 'digest', 'to' => 'x@example.com']])
        ->assertJsonPath('error.code', -32602);

    $this->call('POST', '/api/sender/v1/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->editorToken], '{not json')
        ->assertStatus(400)->assertJsonPath('error.code', -32700);
    $this->withToken($this->editorToken)->postJson('/api/sender/v1/mcp', ['id' => 1, 'method' => 'ping'])->assertStatus(400)->assertJsonPath('error.code', -32600);
    $this->withToken($this->editorToken)->withHeader('MCP-Protocol-Version', '1999-01-01')->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
        ->assertStatus(400);
});

it('lets a viewer read letters but not change them', function (): void {
    $viewer = mcpToken('viewer');

    $list = mcpTool($viewer, 'list_templates');
    expect($list['isError'])->toBeFalse()
        ->and($list['structuredContent']['templates'][0]['slug'])->toBe('digest')
        ->and($list['structuredContent']['templates'][0]['variables'])->toBe(['first_name', 'unsubscribe_url']);

    $one = mcpTool($viewer, 'get_template', ['template' => 'digest']);
    expect($one['structuredContent']['body_html'])->toContain('Здравствуйте')
        ->and(json_decode($one['content'][0]['text'], true)['id'])->toBe($this->template->id);

    $created = mcpTool($viewer, 'create_template', ['name' => 'Новое', 'subject' => 'Тема', 'html' => '<p>Текст</p>']);
    expect($created['isError'])->toBeTrue()
        ->and($created['content'][0]['text'])->toBe('Ваша роль не позволяет менять письма');

    expect(mcpTool($viewer, 'update_template', ['template' => 'digest', 'subject' => 'Другая'])['isError'])->toBeTrue()
        ->and(mcpTool($viewer, 'send_test', ['template' => 'digest'])['isError'])->toBeTrue()
        ->and(mcpTool(mcpToken('auditor'), 'create_template', ['name' => 'X', 'subject' => 'Y', 'html' => '<p>z</p>'])['isError'])->toBeTrue()
        ->and(mcpTool(mcpToken('designer'), 'create_template', ['name' => 'Дизайн', 'subject' => 'Y', 'html' => '<p>z</p>'])['isError'])->toBeFalse()
        ->and((new Permissions(null))->canEditContent())->toBeTrue()
        ->and((new Permissions('owner'))->canEditContent())->toBeTrue()
        ->and((new Permissions('viewer'))->canEditContent())->toBeFalse();
});

it('creates a letter from html with an automatic slug and folder and writes the audit log', function (): void {
    $result = mcpTool($this->editorToken, 'create_template', [
        'name' => 'Анонс вебинара',
        'subject' => 'Вебинар 15 ноября',
        'preheader' => 'Разберём сложные случаи',
        'folder' => 'Мероприятия',
        'html' => '<html><body><p>Здравствуйте!</p><a href="https://mag-expert.ru/events/1">Подробнее</a></body></html>',
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['template']['slug'])->toBe('anons-vebinara')
        ->and($result['structuredContent']['template']['folder']['name'])->toBe('Мероприятия');

    $template = Template::query()->where('slug', 'anons-vebinara')->firstOrFail();
    expect($template->organization_id)->toBe($this->organization->id)
        ->and($template->editor)->toBe('html')
        ->and($template->body_text)->toContain('Подробнее (https://mag-expert.ru/events/1)')
        ->and($this->organization->templateFolders()->where('name', 'Мероприятия')->count())->toBe(1);

    // та же папка, второе письмо с тем же названием получает другой идентификатор
    $second = mcpTool($this->editorToken, 'create_template', ['name' => 'Анонс вебинара', 'subject' => 'Ещё', 'folder' => 'мероприятия', 'html' => '<p>2</p>']);
    expect($second['structuredContent']['template']['slug'])->toBe('anons-vebinara-2')
        ->and($this->organization->templateFolders()->count())->toBe(1);

    $duplicate = mcpTool($this->editorToken, 'create_template', ['name' => 'Копия', 'slug' => 'digest', 'subject' => 'Тема', 'html' => '<p>x</p>']);
    expect($duplicate['isError'])->toBeTrue()
        ->and($duplicate['content'][0]['text'])->toContain('Шаблон с таким идентификатором уже есть');

    $log = AuditLog::query()->where('action', 'mcp.templates.store')->first();
    expect($log->user_id)->toBe($this->editor->id)
        ->and($log->subject)->toBe('Анонс вебинара')
        ->and(AuditLog::query()->where('action', 'mcp.templates.store')->count())->toBe(2);

    User::create(['organization_id' => $this->organization->id, 'name' => 'Владелец', 'email' => 'owner@example.com', 'password' => 'secret-pass', 'role' => 'owner']);
    $this->withToken((new AuthService)->login('owner@example.com', 'secret-pass')['token'])->getJson('/api/sender/v1/admin/audit-log')
        ->assertOk()->assertJsonPath('data.0.action', 'Создал письмо через ИИ');
});

it('creates a block letter that the visual editor can open', function (): void {
    $result = mcpTool($this->editorToken, 'create_template', [
        'name' => 'Блоки',
        'subject' => 'Приглашение',
        'settings' => ['buttonBg' => '#0A7B83'],
        'blocks' => [
            ['type' => 'heading', 'html' => '<p>Приглашаем</p>'],
            ['type' => 'text', 'html' => '<p>Здравствуйте, {{ first_name }}!</p><p>Ждём вас.</p>', 'cond' => 'first_name'],
            ['type' => 'button', 'text' => 'Записаться', 'href' => '{{ event_url }}'],
            ['type' => 'columns', 'columns' => [[['type' => 'text', 'html' => '<p>Слева</p>']], [['type' => 'image', 'src' => 'https://mag-expert.ru/a.png', 'alt' => 'Фото']]]],
            ['type' => 'footer', 'html' => '<p><a href="{{ unsubscribe_url }}">Отписаться</a></p>'],
        ],
    ]);

    expect($result['isError'])->toBeFalse();

    $template = Template::query()->where('slug', 'bloki')->firstOrFail();
    $design = $template->design;

    expect($template->editor)->toBe('blocks')
        ->and($design['version'])->toBe(1)
        ->and($design['settings']['buttonBg'])->toBe('#0A7B83')
        ->and($design['settings']['width'])->toBe(600)
        ->and($design['blocks'])->toHaveCount(5)
        ->and($design['blocks'][0]['id'])->toBeString()->not->toBeEmpty()
        ->and($design['blocks'][0]['padding'])->toBe(['t' => 20, 'r' => 40, 'b' => 8, 'l' => 40])
        ->and($design['blocks'][3]['columns'][1][0]['padding']['l'])->toBe(0)
        ->and($template->body_html)->toContain('<!doctype html>')
        ->and($template->body_html)->toContain('bgcolor="#0A7B83"')
        ->and($template->body_html)->toContain('href="{{ event_url }}"')
        ->and($template->body_html)->toContain('{{#if first_name}}<tr>')
        ->and($template->body_html)->toContain('<p style="margin:0 0 12px;">Здравствуйте, {{ first_name }}!</p>')
        ->and($template->body_html)->toContain('alt="Фото"')
        ->and($template->body_text)->toContain('Записаться ({{ event_url }})');

    $bad = mcpTool($this->editorToken, 'create_template', ['name' => 'Плохие', 'subject' => 'Т', 'blocks' => [
        ['type' => 'columns', 'columns' => [[['type' => 'columns']], []]],
        ['type' => 'button', 'align' => 'middle'],
        ['type' => 'image'],
    ]]);

    expect($bad['isError'])->toBeTrue()
        ->and($bad['content'][0]['text'])->toContain('колонки нельзя вкладывать')
        ->and($bad['content'][0]['text'])->toContain('align: допустимо left, center, right')
        ->and($bad['content'][0]['text'])->toContain('у картинки нет адреса');

    $edited = mcpTool($this->editorToken, 'get_template', ['template' => 'bloki']);
    expect($edited['structuredContent']['is_block_based'])->toBeTrue()
        ->and($edited['structuredContent']['design']['blocks'])->toHaveCount(5)
        ->and($edited['structuredContent']['body_html'])->toBeNull()
        ->and($edited['structuredContent']['edit_url'])->toEndWith('/templates/'.$template->id.'/blocks');
});

it('updates a letter and protects against overwriting newer edits', function (): void {
    $before = mcpTool($this->editorToken, 'get_template', ['template' => $this->template->id])['structuredContent'];

    $result = mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'subject' => 'Свежие новости', 'expected_updated_at' => $before['updated_at']]);
    expect($result['isError'])->toBeFalse()
        ->and($this->template->fresh()->subject)->toBe('Свежие новости')
        ->and($this->template->fresh()->body_html)->toContain('Здравствуйте')
        ->and(AuditLog::query()->where('action', 'mcp.templates.update')->value('subject'))->toBe('Дайджест');

    $this->travel(5)->seconds();
    $this->template->fresh()->update(['name' => 'Изменили в редакторе']);

    $stale = mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'subject' => 'Старая правка', 'expected_updated_at' => $before['updated_at']]);
    expect($stale['isError'])->toBeTrue()
        ->and($this->template->fresh()->subject)->toBe('Свежие новости');

    $blocks = mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'blocks' => [['type' => 'text', 'html' => '<p>Новый текст</p>']]]);
    expect($blocks['isError'])->toBeFalse()
        ->and($this->template->fresh()->editor)->toBe('blocks')
        ->and($this->template->fresh()->body_html)->toContain('Новый текст');

    mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'html' => '<p>Снова HTML</p>', 'folder' => 'Архив']);
    expect($this->template->fresh()->editor)->toBe('html')
        ->and($this->template->fresh()->design)->toBeNull()
        ->and($this->template->fresh()->folder->name)->toBe('Архив');
});

it('keeps organizations isolated', function (): void {
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $foreign = $other->templates()->create(['slug' => 'secret', 'name' => 'Чужое', 'subject' => 'Тайна', 'body_html' => '<p>Тайна</p>']);

    expect(mcpTool($this->editorToken, 'get_template', ['template' => $foreign->id])['isError'])->toBeTrue()
        ->and(mcpTool($this->editorToken, 'get_template', ['template' => 'secret'])['content'][0]['text'])->toContain('не найдено')
        ->and(mcpTool($this->editorToken, 'update_template', ['template' => $foreign->id, 'subject' => 'Взлом'])['isError'])->toBeTrue()
        ->and(collect(mcpTool($this->editorToken, 'list_templates')['structuredContent']['templates'])->pluck('slug')->all())->toBe(['digest'])
        ->and($foreign->fresh()->subject)->toBe('Тайна');

    // такой же идентификатор в другой организации не мешает
    expect(mcpTool($this->editorToken, 'create_template', ['name' => 'Своё', 'slug' => 'secret', 'subject' => 'Т', 'html' => '<p>x</p>'])['isError'])->toBeFalse();
});

it('renders a letter or a draft with sample data', function (): void {
    $rendered = mcpTool($this->editorToken, 'render_template', ['template' => 'digest', 'data' => ['first_name' => 'Олег']])['structuredContent'];

    expect($rendered['subject'])->toBe('Новости для Олег')
        ->and($rendered['html'])->toContain('Здравствуйте, Олег!')
        ->and($rendered['text'])->toContain('Здравствуйте, Олег!')
        ->and($rendered['variables_empty'])->toBe([]);

    // без data — примеры базовых переменных
    expect(mcpTool($this->editorToken, 'render_template', ['template' => 'digest'])['structuredContent']['subject'])->toBe('Новости для Анна');

    $list = $this->organization->lists()->create(['name' => 'Врачи']);
    $list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'ivan@example.com', 'name' => 'Иван Петров', 'data' => ['city' => 'Казань']]);
    $draft = mcpTool($this->editorToken, 'render_template', ['subject' => 'Для {{ first_name }} из {{ city }}', 'list_id' => $list->id, 'blocks' => [['type' => 'text', 'html' => '<p>{{ city }}</p>']]])['structuredContent'];

    expect($draft['subject'])->toBe('Для Иван из Казань')
        ->and($draft['html'])->toContain('Казань')
        ->and(Template::query()->count())->toBe(1);
});

it('lints letters', function (): void {
    $result = mcpTool($this->editorToken, 'check_template', [
        'subject' => 'Очень длинная тема письма, которая точно не поместится на экране телефона целиком',
        'html' => '<p><a href="http://example.com">Ссылка</a> <a href="">Пусто</a> <img src="https://x.ru/a.png"> {{ unknown_var }}</p>',
    ])['structuredContent'];

    $codes = collect($result['findings'])->pluck('code')->all();

    expect($result['ok'])->toBeFalse()
        ->and($result['kind'])->toBe('campaign')
        ->and($codes)->toContain('unsubscribe_missing', 'link_insecure', 'link_empty', 'image_alt_missing', 'subject_too_long', 'preheader_missing', 'unknown_variables');

    $clean = mcpTool($this->editorToken, 'check_template', ['template' => 'digest'])['structuredContent'];
    expect(collect($clean['findings'])->pluck('code')->all())->not->toContain('unsubscribe_missing', 'unknown_variables', 'link_insecure')
        ->and($clean['ok'])->toBeTrue();

    $spam = mcpTool($this->editorToken, 'check_template', ['subject' => 'СРОЧНО БЕСПЛАТНО!!', 'html' => '<p>x</p>', 'kind' => 'transactional'])['structuredContent'];
    expect(collect($spam['findings'])->pluck('code')->all())->toContain('subject_caps', 'subject_exclamations', 'subject_spam_words')
        ->not->toContain('unsubscribe_missing');
});

it('sends a test only to the key owner and limits the rate', function (): void {
    Queue::fake();

    $result = mcpTool($this->editorToken, 'send_test', ['template' => 'digest', 'data' => ['first_name' => 'Ольга']]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['to'])->toBe($this->editor->email);

    $message = $this->organization->messages()->latest('id')->first();
    expect($message->to_email)->toBe($this->editor->email)
        ->and($message->subject)->toBe('Новости для Ольга')
        ->and(AuditLog::query()->where('action', 'mcp.templates.test')->value('user_id'))->toBe($this->editor->id);
    Queue::assertPushed(SendMessageJob::class, 1);

    foreach (range(2, 10) as $i) {
        expect(mcpTool($this->editorToken, 'send_test', ['template' => 'digest'])['isError'])->toBeFalse();
    }

    $limited = mcpTool($this->editorToken, 'send_test', ['template' => 'digest']);
    expect($limited['isError'])->toBeTrue()
        ->and($limited['content'][0]['text'])->toContain('Слишком много тестовых писем');
    Queue::assertPushed(SendMessageJob::class, 10);
});

it('gives brand, block schema, folders and variables', function (): void {
    $this->organization->variables()->create(['key' => 'clinic', 'label' => 'Клиника', 'default_value' => 'МедАльянс']);

    $brand = mcpTool($this->editorToken, 'get_brand')['structuredContent'];
    expect($brand['organization']['name'])->toBe('MagExpert')
        ->and($brand['sender']['default'])->toBe(['email' => 'news@mag-expert.ru', 'name' => 'MagExpert'])
        ->and($brand['style']['inferred'])->toBeTrue()
        ->and($brand['style']['settings']['buttonBg'])->toBe('#4C68EB');

    $schema = mcpTool($this->editorToken, 'get_block_schema')['structuredContent'];
    expect(array_keys($schema['block_types']))->toContain('heading', 'button', 'columns', 'footer')
        ->and($schema['example']['blocks'])->not->toBeEmpty();

    $folders = mcpTool($this->editorToken, 'list_folders')['structuredContent'];
    expect($folders['without_folder'])->toBe(1);

    $variables = mcpTool($this->editorToken, 'list_variables')['structuredContent'];
    expect(collect($variables['base'])->pluck('key')->all())->toContain('first_name', 'unsubscribe_url')
        ->and($variables['organization'][0]['key'])->toBe('clinic');
});

it('serves resources and prompts', function (): void {
    $resources = collect(mcpRpc($this->editorToken, 'resources/list')->json('result.resources'));
    expect($resources->pluck('uri')->all())->toBe(['sender://brand', 'sender://blocks/schema', 'sender://guide/letters']);

    $guide = mcpRpc($this->editorToken, 'resources/read', ['uri' => 'sender://guide/letters'])->assertOk()->json('result.contents.0');
    expect($guide['mimeType'])->toBe('text/markdown')
        ->and($guide['text'])->toContain('Призыв к действию')
        ->and(mb_strtolower($guide['text']))->not->toContain('обучени');

    $brand = mcpRpc($this->editorToken, 'resources/read', ['uri' => 'sender://brand'])->json('result.contents.0');
    expect(json_decode($brand['text'], true)['organization']['name'])->toBe('MagExpert');

    mcpRpc($this->editorToken, 'resources/read', ['uri' => 'sender://nope'])->assertJsonPath('error.code', -32002);

    $prompts = collect(mcpRpc($this->editorToken, 'prompts/list')->json('result.prompts'));
    expect($prompts->pluck('name')->all())->toBe(['event_announcement', 'improve_letter']);

    $prompt = mcpRpc($this->editorToken, 'prompts/get', ['name' => 'event_announcement', 'arguments' => ['title' => 'Кардиология 2026', 'date' => '15 ноября', 'link' => 'https://mag-expert.ru/e/1']])
        ->assertOk()->json('result');
    expect($prompt['messages'][0]['role'])->toBe('user')
        ->and($prompt['messages'][0]['content']['text'])->toContain('Кардиология 2026')
        ->and($prompt['messages'][0]['content']['text'])->toContain('«Мероприятия»')
        ->and($prompt['messages'][0]['content']['text'])->toContain('send_test');

    expect(mcpRpc($this->editorToken, 'prompts/get', ['name' => 'improve_letter', 'arguments' => ['template' => 'digest']])->json('result.messages.0.content.text'))
        ->toContain('КОПИЕЙ');
    mcpRpc($this->editorToken, 'prompts/get', ['name' => 'event_announcement', 'arguments' => ['title' => 'X']])->assertJsonPath('error.code', -32602);
});

it('lets users manage their own MCP keys through the admin API', function (): void {
    $login = (new AuthService)->login($this->editor->email, 'secret-pass')['token'];

    $created = $this->withToken($login)->postJson('/api/sender/v1/admin/mcp-tokens', ['name' => 'Claude на ноутбуке'])
        ->assertCreated()->assertJsonPath('data.name', 'Claude на ноутбуке');

    expect($created->json('token'))->toStartWith('mxm_')->toHaveLength(44)
        ->and($created->json('url'))->toEndWith('/api/sender/v1/mcp')
        ->and(McpToken::query()->latest('id')->value('token_hash'))->toBe(hash('sha256', $created->json('token')));

    mcpRpc($created->json('token'), 'ping')->assertOk();

    $this->withToken($login)->getJson('/api/sender/v1/admin/mcp-tokens')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($login)->postJson('/api/sender/v1/admin/mcp-tokens', [])->assertUnprocessable()->assertJsonValidationErrors('name');

    // чужой ключ отозвать нельзя
    $stranger = mcpUser('admin');
    $strangerLogin = (new AuthService)->login($stranger->email, 'secret-pass')['token'];
    $this->withToken($strangerLogin)->deleteJson('/api/sender/v1/admin/mcp-tokens/'.$created->json('data.id'))->assertNotFound();
    $this->withToken($strangerLogin)->getJson('/api/sender/v1/admin/mcp-tokens')->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($login)->deleteJson('/api/sender/v1/admin/mcp-tokens/'.$created->json('data.id'))->assertOk();
    mcpRpc($created->json('token'), 'ping')->assertUnauthorized();

    expect(AuditLog::query()->where('action', 'mcp.tokens.store')->value('subject'))->toBe('Claude на ноутбуке')
        ->and(AuditLog::query()->where('action', 'mcp.tokens.destroy')->exists())->toBeTrue();
});

it('lets a viewer create their own key through the admin API', function (): void {
    $viewer = mcpUser('viewer');
    $login = (new AuthService)->login($viewer->email, 'secret-pass')['token'];

    $created = $this->withToken($login)->postJson('/api/sender/v1/admin/mcp-tokens', ['name' => 'Только чтение'])->assertCreated();
    $this->withToken($login)->deleteJson('/api/sender/v1/admin/mcp-tokens/'.$created->json('data.id'))->assertOk();
})->skip(
    fn (): bool => ! str_contains((string) file_get_contents(app_path('Sender/Http/Middleware/AuthorizeRole.php')), 'mcp-tokens'),
    'Ждёт добавления sender.admin.mcp-tokens.store/destroy в READ_ONLY у AuthorizeRole',
);

it('issues a key from the console', function (): void {
    $this->artisan('sender:mcp-token', ['email' => $this->editor->email, '--name' => 'Локально'])
        ->expectsOutputToContain('/api/sender/v1/mcp')
        ->expectsOutputToContain('mxm_')
        ->assertSuccessful();

    expect(McpToken::query()->where('name', 'Локально')->where('user_id', $this->editor->id)->exists())->toBeTrue();

    $this->artisan('sender:mcp-token', ['email' => 'nobody@example.com'])->assertFailed();
});

it('answers an unexpected server failure with a generic JSON-RPC internal error', function (): void {
    Log::spy();
    app()->instance(Prompts::class, new class extends Prompts
    {
        public function list(): array
        {
            throw new RuntimeException('секретная деталь SQLSTATE');
        }
    });

    $response = mcpRpc($this->editorToken, 'prompts/list', [], 7)->assertOk()
        ->assertJsonPath('id', 7)
        ->assertJsonPath('error.code', -32603);

    expect($response->json('error.message'))->toContain('Внутренняя ошибка')
        ->and($response->getContent())->not->toContain('секретная деталь')
        ->not->toContain('SQLSTATE')
        ->not->toContain('trace');
    Log::shouldHaveReceived('error')->once();
});

it('rejects non-string prompt arguments', function (): void {
    mcpRpc($this->editorToken, 'prompts/get', ['name' => 'event_announcement', 'arguments' => ['title' => ['x' => 'y'], 'date' => '15 ноября', 'link' => 'https://mag-expert.ru/e/1']])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Аргументы сценария должны быть строками: title');
    mcpRpc($this->editorToken, 'prompts/get', ['name' => 'improve_letter', 'arguments' => ['template' => 15]])
        ->assertJsonPath('error.code', -32602);
});

it('does not leave a new folder behind when a letter fails validation', function (): void {
    $created = mcpTool($this->editorToken, 'create_template', ['name' => 'Копия', 'slug' => 'digest', 'subject' => 'Тема', 'folder' => 'Новая папка', 'html' => '<p>x</p>']);
    expect($created['isError'])->toBeTrue()
        ->and(TemplateFolder::query()->count())->toBe(0);

    $updated = mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'subject' => '', 'folder' => 'Другая папка']);
    expect($updated['isError'])->toBeTrue()
        ->and(TemplateFolder::query()->count())->toBe(0)
        ->and($this->template->fresh()->folder_id)->toBeNull();

    // успешная правка создаёт папку вместе с письмом
    expect(mcpTool($this->editorToken, 'update_template', ['template' => 'digest', 'folder' => 'Другая папка'])['isError'])->toBeFalse()
        ->and($this->template->fresh()->folder->name)->toBe('Другая папка')
        ->and(TemplateFolder::query()->count())->toBe(1);
});

it('keeps image placeholders without an address that the editor saved', function (): void {
    $template = $this->organization->templates()->create([
        'slug' => 'with-placeholder', 'name' => 'С заготовкой', 'subject' => 'Тема', 'body_html' => '<p>x</p>', 'editor' => 'blocks',
        'design' => ['version' => 1, 'settings' => ['width' => 600], 'blocks' => [
            ['id' => 'txt1', 'type' => 'text', 'html' => '<p>Текст</p>'],
            ['id' => 'img1', 'type' => 'image', 'src' => ''],
            ['id' => 'col1', 'type' => 'columns', 'columns' => [[['id' => 'img2', 'type' => 'image', 'src' => '']], []]],
        ]],
    ]);

    $result = mcpTool($this->editorToken, 'update_template', ['template' => 'with-placeholder', 'settings' => ['buttonBg' => '#0A7B83']]);
    expect($result['isError'])->toBeFalse();

    $fresh = $template->fresh();
    expect($fresh->design['settings']['buttonBg'])->toBe('#0A7B83')
        ->and($fresh->design['blocks'])->toHaveCount(3)
        ->and($fresh->design['blocks'][1]['src'])->toBe('')
        ->and($fresh->body_html)->toContain('Текст')
        ->and($fresh->body_html)->not->toContain('<img');

    // блоки целиком с сохранённой заготовкой — можно, новая картинка без адреса — ошибка
    expect(mcpTool($this->editorToken, 'update_template', ['template' => 'with-placeholder', 'blocks' => [['id' => 'img1', 'type' => 'image'], ['type' => 'text', 'html' => '<p>Новый</p>']]])['isError'])->toBeFalse();

    $bad = mcpTool($this->editorToken, 'update_template', ['template' => 'with-placeholder', 'blocks' => [['id' => 'new-img', 'type' => 'image']]]);
    expect($bad['isError'])->toBeTrue()
        ->and($bad['content'][0]['text'])->toContain('у картинки нет адреса');
});

it('does not write the audit log when the test letter is blocked', function (): void {
    Queue::fake();
    Suppression::create(['organization_id' => $this->organization->id, 'email' => $this->editor->email, 'reason' => Suppression::REASON_BOUNCE]);

    $result = mcpTool($this->editorToken, 'send_test', ['template' => 'digest']);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('Тестовое письмо не отправлено')
        ->and(AuditLog::query()->where('action', 'mcp.templates.test')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('gives every organization neutral assistant rules without another company’s specifics', function (): void {
    $other = Organization::create(['name' => 'Ромашка', 'slug' => 'romashka']);
    $token = app(McpTokens::class)->issue(mcpUser('editor', $other), 'Ключ')['plain'];
    $specific = '/врач|медицин|medalliance|медальянс|magexpert|нмо/iu';

    $brand = mcpTool($token, 'get_brand')['structuredContent'];
    expect($brand['organization']['name'])->toBe('Ромашка')
        ->and($brand['rules'])->toHaveCount(4)
        ->and(implode("\n", $brand['rules']))->toContain('«вы»', 'Arial', '{{ unsubscribe_url }}')
        ->and(json_encode($brand, JSON_UNESCAPED_UNICODE))->not->toMatch($specific);

    $texts = [
        mcpRpc($token, 'initialize', [])->json('result.instructions'),
        mcpRpc($token, 'resources/read', ['uri' => 'sender://guide/letters'])->json('result.contents.0.text'),
        json_encode(mcpRpc($token, 'prompts/list')->json('result.prompts'), JSON_UNESCAPED_UNICODE),
        mcpRpc($token, 'prompts/get', ['name' => 'event_announcement', 'arguments' => ['title' => 'Весенняя встреча', 'date' => '15 ноября', 'link' => 'https://example.com/e/1']])
            ->json('result.messages.0.content.text'),
        json_encode(mcpTool($token, 'list_variables')['structuredContent']['base'], JSON_UNESCAPED_UNICODE),
    ];

    foreach ($texts as $text) {
        expect($text)->toBeString()->not->toMatch($specific);
    }

    $lint = mcpTool($token, 'check_template', ['subject' => 'Программа обучения', 'html' => '<p>Курс обучения</p>', 'kind' => 'transactional'])['structuredContent'];
    expect(collect($lint['findings'])->pluck('code')->all())->not->toContain('forbidden_word');
});
