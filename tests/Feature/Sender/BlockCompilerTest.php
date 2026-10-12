<?php

use App\Sender\Mcp\Support\BlockCompiler;
use App\Sender\Mcp\ToolException;
use App\Sender\Support\HtmlSanitizer;

function compileBlocks(array $blocks): string
{
    $compiler = new BlockCompiler;

    return $compiler->toHtml($compiler->normalize($blocks));
}

it('keeps a full-width image edge to edge on phones', function (): void {
    $html = compileBlocks([
        ['type' => 'image', 'src' => 'https://example.com/cover.png', 'alt' => '', 'full' => true, 'padding' => ['t' => 0, 'r' => 0, 'b' => 0, 'l' => 0]],
    ]);

    expect($html)->toContain('cover.png')
        ->and($html)->not->toMatch('/<td class="px"[^>]*>(?:(?!<\/td>).)*cover\.png/s');
});

it('adds phone side padding to blocks with their own side padding', function (): void {
    $html = compileBlocks([
        ['type' => 'text', 'html' => '<p>Текст письма</p>'],
    ]);

    expect($html)->toMatch('/<td class="px"[^>]*><div[^>]*>.*Текст письма/s');
});

it('keeps phone padding for an inset card even without side padding', function (): void {
    $html = compileBlocks([
        ['type' => 'text', 'html' => '<p>Карточка</p>', 'bg' => '#F2F4FA', 'inset' => true, 'padding' => ['t' => 18, 'r' => 0, 'b' => 18, 'l' => 0]],
    ]);

    expect($html)->toMatch('/<td class="px"[^>]*><div[^>]*background-color:#F2F4FA.*Карточка/s');
});

it('keeps zero block layers and sends the rendered picture', function (): void {
    $compiler = new BlockCompiler;
    $design = $compiler->normalize([[
        'type' => 'zero', 'src' => 'https://example.com/zero.png', 'alt' => 'Шапка письма', 'href' => 'https://example.com/event',
        'layers' => [['kind' => 'text', 'text' => 'Привет'], ['kind' => 'unknown'], 'мусор'],
    ]]);

    $block = $design['blocks'][0];
    $html = $compiler->toHtml($design);

    expect($block['layers'])->toHaveCount(1)
        ->and($block['layers'][0]['text'])->toBe('Привет')
        ->and($block['padding'])->toBe(['t' => 0, 'r' => 0, 'b' => 0, 'l' => 0])
        ->and($html)->toContain('src="https://example.com/zero.png"')
        ->and($html)->toContain('alt="Шапка письма"')
        ->and($html)->toContain('href="https://example.com/event"');
});

it('skips a zero block that has not been rendered yet', function (): void {
    $html = compileBlocks([['type' => 'zero', 'layers' => [['kind' => 'rect']]], ['type' => 'text', 'html' => '<p>После шапки</p>']]);

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('После шапки');
});

it('does not allow a zero block inside columns', function (): void {
    compileBlocks([['type' => 'columns', 'columns' => [[['type' => 'zero']], []]]]);
})->throws(ToolException::class);

it('cleans colors and photo addresses inside zero layers', function (): void {
    $block = (new HtmlSanitizer)->block([
        'type' => 'zero',
        'layers' => [
            ['kind' => 'rect', 'fill' => 'url(https://evil.example/x.png)', 'stroke' => '#111111'],
            ['kind' => 'image', 'src' => 'javascript:alert(1)'],
            ['kind' => 'image', 'src' => 'https://example.com/photo.png'],
        ],
    ]);

    expect($block['layers'][0]['fill'])->toBe('')
        ->and($block['layers'][0]['stroke'])->toBe('#111111')
        ->and($block['layers'][1]['src'])->toBe('')
        ->and($block['layers'][2]['src'])->toBe('https://example.com/photo.png');
});
