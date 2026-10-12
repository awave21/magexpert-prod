<?php

use App\Sender\Mcp\McpTokens;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Support\HtmlSanitizer;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->admin = User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass', 'role' => 'admin']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
    $this->mcp = app(McpTokens::class)->issue($this->admin, 'Тест')['plain'];
});

const SENDER_SAFE_EMAIL_HTML = '<!doctype html><html><head><meta charset="utf-8"><style>td{color:#333}</style></head><body>'
    .'<!--[if mso]><table><tr><td><![endif]-->'
    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#EEF1F6">'
    .'<tr><td style="padding:24px;font-family:Arial, sans-serif">Здравствуйте, {{ first_name }}!</td></tr>'
    .'{{#if city}}<tr><td>Город: {{ city }}</td></tr>{{/if}}'
    .'<tr><td><img src="https://mag-expert.ru/logo.png" alt="Лого" width="120" style="display:block"></td></tr>'
    .'<tr><td><a href="{{ unsubscribe_url }}" style="color:#3B51D3" target="_blank">Отписаться</a></td></tr>'
    .'</table><!--[if mso]></td></tr></table><![endif]--></body></html>';

function sanitizeApi(string $path = ''): string
{
    return '/api/sender/v1/admin/templates'.$path;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function sanitizeTool(string $token, string $name, array $arguments): array
{
    return test()->withToken($token)->postJson('/api/sender/v1/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk()->json('result');
}

function expectNoScript(string $html): void
{
    expect(strtolower($html))->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('onclick')
        ->not->toContain('onload')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe')
        ->not->toContain('srcdoc');
}

it('removes scripts, event handlers and script urls', function (): void {
    $sanitizer = new HtmlSanitizer;

    $clean = $sanitizer->clean('<p onclick="steal()">Текст <b>жирный</b></p><script>alert(1)</script>'
        .'<img src="x" onerror="alert(1)"><a href="java&#x09;script:alert(1)">ссылка</a><a href=" JAVASCRIPT:alert(1)">2</a>'
        .'<iframe srcdoc="<script>1</script>"></iframe><object data="x.swf"></object><embed src="x"><form action="https://evil.ru"><input name="q"><p>в форме</p></form>'
        .'<svg><script>1</script><a xlink:href="javascript:1">s</a></svg><math><mi>1</mi></math>'
        .'<div style="width:expression(alert(1))">e</div><div style="background:url(&quot;javascript:alert(1)&quot;)">u</div>'
        .'<img src="data:text/html;base64,PHNjcmlwdD4="><img src="data:image/png;base64,iVBORw0K" alt="ok">'
        .'<a href="data:text/html,<script>1</script>">d</a><a href="vbscript:msgbox(1)">v</a><base href="https://evil.ru/"><link rel="import" href="x">');

    expectNoScript($clean);
    expect($clean)->toContain('<p>Текст <b>жирный</b></p>')
        ->toContain('<p>в форме</p>')
        ->toContain('src="data:image/png;base64,iVBORw0K"')
        ->not->toContain('data:text/html')
        ->not->toContain('vbscript')
        ->not->toContain('expression(')
        ->not->toContain('<svg')
        ->not->toContain('<math')
        ->not->toContain('<base')
        ->not->toContain('<link')
        ->not->toContain('<object')
        ->not->toContain('<embed')
        ->not->toContain('<input')
        ->not->toContain('<form');
});

it('strips dangerous meta, noscript tricks and css in style elements', function (): void {
    $sanitizer = new HtmlSanitizer;

    $doc = $sanitizer->clean('<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=javascript:alert(1)">'
        .'<style>a{color:red}b{background:url(javascript:alert(1))}i{width:expression(alert(1))}</style></head>'
        .'<body onload="alert(1)"><noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript><p>Текст</p></body></html>');

    expectNoScript($doc);
    expect($doc)->toContain('<meta charset="utf-8">')
        ->toContain('a{color:red}')
        ->toContain('<p>Текст</p>')
        ->not->toContain('refresh')
        ->not->toContain('expression(')
        ->not->toContain('noscript');
});

it('keeps normal email html untouched, including variables, conditions between rows and outlook comments', function (): void {
    $sanitizer = new HtmlSanitizer;

    expect($sanitizer->clean(SENDER_SAFE_EMAIL_HTML))->toBe(SENDER_SAFE_EMAIL_HTML)
        ->and($sanitizer->fragment('<p>Здравствуйте, {{ first_name }}! <a href="{{ unsubscribe_url }}">Отписаться</a></p>'))
        ->toBe('<p>Здравствуйте, {{ first_name }}! <a href="{{ unsubscribe_url }}">Отписаться</a></p>');

    // письмо с опасной вставкой пересобирается, но условия между строками таблицы остаются на месте
    $cleaned = $sanitizer->clean(str_replace('</body>', '<script>alert(1)</script></body>', SENDER_SAFE_EMAIL_HTML));
    expectNoScript($cleaned);
    expect($cleaned)->toContain('{{#if city}}<tr><td>Город: {{ city }}</td></tr>{{/if}}')
        ->toContain('href="{{ unsubscribe_url }}"')
        ->toContain('style="background-color:#EEF1F6"')
        ->toContain('Здравствуйте, {{ first_name }}!');
});

it('sanitizes html and block design saved from the admin ui', function (): void {
    $design = ['version' => 1, 'settings' => ['width' => 600, 'buttonBg' => '#4C68EB" onmouseover="alert(1)'], 'blocks' => [
        ['id' => 'h1', 'type' => 'heading', 'html' => '<p>Заголовок<img src=x onerror="alert(1)"></p>'],
        ['id' => 't1', 'type' => 'text', 'html' => '<p>Текст <a href="javascript:alert(1)">ссылка</a> <a href="{{ unsubscribe_url }}">отписка</a></p>'],
        ['id' => 'b1', 'type' => 'button', 'text' => 'Кнопка', 'href' => 'javascript:alert(1)'],
        ['id' => 'c1', 'type' => 'columns', 'columns' => [[['id' => 'x1', 'type' => 'html', 'html' => '<table><tr><td style="color:red">ok</td></tr></table><script>alert(1)</script>']], []]],
        ['id' => 'f1', 'type' => 'footer', 'html' => '<p onclick="alert(1)">Подвал</p>'],
        ['id' => 's1', 'type' => 'social', 'links' => [['label' => 'VK', 'url' => 'javascript:alert(1)'], ['label' => 'TG', 'url' => 'https://t.me/x']]],
    ]];

    $id = $this->withToken($this->token)->postJson(sanitizeApi(), [
        'slug' => 'blocks', 'name' => 'Блоки', 'subject' => 'Тема', 'editor' => 'blocks', 'design' => $design,
        'body_html' => '<table><tr><td>Привет</td></tr></table><img src="x" onerror="alert(1)"><script>alert(1)</script>',
    ])->assertCreated()->json('data.id');

    $template = Template::query()->findOrFail($id);
    $saved = $template->design;

    expectNoScript($template->body_html);
    expectNoScript(json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect($template->body_html)->toContain('<td>Привет</td>')
        ->and($saved['blocks'][0]['html'])->toBe('<p>Заголовок<img src="x"></p>')
        ->and($saved['blocks'][1]['html'])->toContain('href="{{ unsubscribe_url }}"')
        ->and($saved['blocks'][2]['href'])->toBe('')
        ->and($saved['blocks'][3]['columns'][0][0]['html'])->toBe('<table><tbody><tr><td style="color:red">ok</td></tr></tbody></table>')
        ->and($saved['blocks'][4]['html'])->toBe('<p>Подвал</p>')
        ->and($saved['blocks'][5]['links'][0]['url'])->toBe('')
        ->and($saved['blocks'][5]['links'][1]['url'])->toBe('https://t.me/x')
        ->and($saved['settings']['buttonBg'])->not->toContain('"')
        ->and($saved['settings']['width'])->toBe(600);

    // правка тоже очищается, обычное письмо сохраняется как есть
    $this->withToken($this->token)->putJson(sanitizeApi("/{$id}"), [
        'slug' => 'blocks', 'name' => 'Блоки', 'subject' => 'Тема', 'editor' => 'html', 'body_html' => '<p onmouseover="x()">Новый</p><iframe src="https://evil.ru"></iframe>',
    ])->assertOk();
    expect($template->fresh()->body_html)->toBe('<p>Новый</p>');

    $this->withToken($this->token)->putJson(sanitizeApi("/{$id}"), [
        'slug' => 'blocks', 'name' => 'Блоки', 'subject' => 'Тема', 'editor' => 'html', 'body_html' => SENDER_SAFE_EMAIL_HTML,
    ])->assertOk();
    expect($template->fresh()->body_html)->toBe(SENDER_SAFE_EMAIL_HTML);
});

it('sanitizes letters written by the assistant in html and block mode', function (): void {
    $html = sanitizeTool($this->mcp, 'create_template', [
        'name' => 'HTML', 'subject' => 'Тема',
        'html' => '<html><body><p>Текст</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="x()">ссылка</a><a href="{{ unsubscribe_url }}">Отписаться</a></body></html>',
    ]);
    expect($html['isError'])->toBeFalse();

    $template = Template::query()->where('name', 'HTML')->firstOrFail();
    expectNoScript($template->body_html);
    expect($template->body_html)->toContain('<p>Текст</p>')->toContain('href="{{ unsubscribe_url }}"');

    $blocks = sanitizeTool($this->mcp, 'create_template', [
        'name' => 'Блоки', 'subject' => 'Тема', 'settings' => ['outerBg' => '#fff" onload="alert(1)'],
        'blocks' => [
            ['type' => 'text', 'html' => '<p>Привет<img src=x onerror=alert(1)></p>'],
            ['type' => 'html', 'html' => '<table><tr><td style="padding:8px">Свой HTML</td></tr></table><script>alert(1)</script><iframe src="x"></iframe>'],
            ['type' => 'button', 'text' => 'Кнопка', 'href' => 'javascript:alert(1)'],
            ['type' => 'image', 'src' => 'https://mag-expert.ru/a.png', 'href' => 'vbscript:x'],
            ['type' => 'footer', 'html' => '<p><a href="{{ unsubscribe_url }}">Отписаться</a></p>'],
        ],
    ]);
    expect($blocks['isError'])->toBeFalse();

    $template = Template::query()->where('name', 'Блоки')->firstOrFail();
    expectNoScript($template->body_html);
    expectNoScript(json_encode($template->design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect($template->body_html)->toContain('Свой HTML')
        ->toContain('style="padding:8px"')
        ->toContain('href="{{ unsubscribe_url }}"')
        ->not->toContain('onload')
        ->not->toContain('vbscript')
        ->and($template->design['settings']['outerBg'])->toBe('#EEF1F6');

    // правка ассистентом
    $update = sanitizeTool($this->mcp, 'update_template', ['template' => $template->id, 'html' => '<p>Новое</p><img src="x" onerror="alert(1)">']);
    expect($update['isError'])->toBeFalse()
        ->and($template->fresh()->body_html)->toBe('<p>Новое</p><img src="x">');

    $update = sanitizeTool($this->mcp, 'update_template', ['template' => $template->id, 'blocks' => [['type' => 'text', 'html' => '<p onclick="x()">Блок</p><script>1</script>']]]);
    expect($update['isError'])->toBeFalse()
        ->and($template->fresh()->design['blocks'][0]['html'])->toBe('<p>Блок</p>');
    expectNoScript($template->fresh()->body_html);

    // письмо только из опасного HTML после очистки пустое — не сохраняется
    $empty = sanitizeTool($this->mcp, 'create_template', ['name' => 'Пусто', 'subject' => 'Тема', 'html' => '<script>alert(1)</script>']);
    expect($empty['isError'])->toBeTrue()
        ->and(Template::query()->where('name', 'Пусто')->exists())->toBeFalse();
});
