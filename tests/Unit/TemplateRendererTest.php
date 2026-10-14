<?php

use App\Sender\Models\Template;
use App\Sender\Services\TemplateRenderer;

function renderTemplate(string $html, array $data, ?string $text = null): array
{
    $template = new Template(['subject' => 'Тема', 'body_html' => $html, 'body_text' => $text]);

    return (new TemplateRenderer)->render($template, $data);
}

it('repeats an each block for every list item', function (): void {
    $result = renderTemplate('<ul>{{#each speakers}}<li>{{ name }}</li>{{/each}}</ul>', [
        'speakers' => [['name' => 'Иван'], ['name' => 'Пётр'], ['name' => 'Анна']],
    ]);

    expect($result['html'])->toBe('<ul><li>Иван</li><li>Пётр</li><li>Анна</li></ul>');
});

it('renders an empty string for an empty or missing list', function (): void {
    expect(renderTemplate('a{{#each speakers}}<b>{{ name }}</b>{{/each}}z', ['speakers' => []])['html'])->toBe('az')
        ->and(renderTemplate('a{{#each speakers}}<b>{{ name }}</b>{{/each}}z', [])['html'])->toBe('az');
});

it('evaluates nested if blocks per item and keeps outer variables available', function (): void {
    $result = renderTemplate(
        '{{#each speakers}}<p>{{#if photo}}<img src="{{ photo }}"> {{/if}}{{ name }}{{#if position}}, {{ position }}{{/if}} ({{ event }})</p>{{/each}}',
        [
            'event' => 'Вебинар',
            'speakers' => [
                ['name' => 'Иван', 'photo' => 'https://example.com/1.webp', 'position' => 'Профессор'],
                ['name' => 'Пётр'],
            ],
        ],
    );

    expect($result['html'])->toBe('<p><img src="https://example.com/1.webp"> Иван, Профессор (Вебинар)</p><p>Пётр (Вебинар)</p>');
});

it('does not leak a field of one item into the next item', function (): void {
    $result = renderTemplate('{{#each speakers}}[{{ name }}{{#if company}}:{{ company }}{{/if}}]{{/each}}', [
        'speakers' => [['name' => 'A', 'company' => 'X'], ['name' => 'B']],
    ]);

    expect($result['html'])->toBe('[A:X][B]');
});

it('escapes item values in html but not in the text version', function (): void {
    $result = renderTemplate(
        '{{#each speakers}}<p>{{ name }}</p>{{/each}}',
        ['speakers' => [['name' => '<b>Иван</b> & Ко']]],
        '{{#each speakers}}{{ name }};{{/each}}',
    );

    expect($result['html'])->toBe('<p>&lt;b&gt;Иван&lt;/b&gt; &amp; Ко</p>')
        ->and($result['text'])->toBe('<b>Иван</b> & Ко;');
});

it('does not expand template syntax that comes from item values', function (): void {
    $result = renderTemplate('{{#each speakers}}{{ name }}{{/each}} {{ password }}', [
        'password' => 'secret',
        'speakers' => [['name' => '{{ password }}']],
    ]);

    expect($result['html'])->toBe('{{ password }} secret');
});

it('lets an if block wrap an each block', function (): void {
    $template = '{{#if speakers}}<h2>Спикеры</h2>{{#each speakers}}<p>{{ name }}</p>{{/each}}{{/if}}';

    expect(renderTemplate($template, ['speakers' => [['name' => 'Иван']]])['html'])->toBe('<h2>Спикеры</h2><p>Иван</p>')
        ->and(renderTemplate($template, ['speakers' => []])['html'])->toBe('');
});
