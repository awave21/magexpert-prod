<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Http\Requests\Admin\TemplateRequest;
use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Template;
use App\Sender\Models\TemplateFolder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Создание и правка письма ассистентом по тем же правилам, что и в админке (TemplateRequest).
 * Содержимое — блоки (сервер соберёт HTML, письмо откроется в визуальном редакторе) или готовый HTML.
 */
class TemplateWriter
{
    public function __construct(private readonly BlockCompiler $compiler) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function create(McpContext $ctx, array $args): Template
    {
        $name = trim((string) ($args['name'] ?? ''));
        $data = [
            'name' => $name,
            'slug' => filled($args['slug'] ?? null) ? trim((string) $args['slug']) : $this->uniqueSlug($ctx, $name),
            'subject' => (string) ($args['subject'] ?? ''),
            'preheader' => $this->nullable($args['preheader'] ?? null),
            'reply_to' => $this->nullable($args['reply_to'] ?? null),
            'sender_address_id' => $args['sender_address_id'] ?? null,
            'folder_id' => $this->folderId($ctx, $args),
        ];

        if (! isset($args['blocks']) && ! isset($args['html'])) {
            throw new ToolException('Передайте содержимое письма: blocks (блоки визуального редактора) или html');
        }

        $data = array_merge($data, $this->content($args, $data['subject']));
        $validated = $this->validate($ctx, $data, null);

        return $ctx->organization->templates()->create($validated)->load('folder');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function update(McpContext $ctx, Template $template, array $args): Template
    {
        $expected = $args['expected_updated_at'] ?? null;

        if (filled($expected) && $template->updated_at?->toIso8601String() !== (string) $expected) {
            throw new ToolException('Письмо изменилось после того, как вы его прочитали (updated_at '.$template->updated_at?->toIso8601String().'). Получите его заново через get_template и повторите правку.');
        }

        $data = [
            'name' => array_key_exists('name', $args) ? trim((string) $args['name']) : $template->name,
            'slug' => array_key_exists('slug', $args) ? trim((string) $args['slug']) : $template->slug,
            'subject' => array_key_exists('subject', $args) ? (string) $args['subject'] : $template->subject,
            'preheader' => array_key_exists('preheader', $args) ? $this->nullable($args['preheader']) : $template->preheader,
            'reply_to' => array_key_exists('reply_to', $args) ? $this->nullable($args['reply_to']) : $template->reply_to,
            'sender_address_id' => array_key_exists('sender_address_id', $args) ? $args['sender_address_id'] : $template->sender_address_id,
            'folder_id' => array_key_exists('folder', $args) || array_key_exists('folder_id', $args) ? $this->folderId($ctx, $args) : $template->folder_id,
            'body_html' => $template->body_html,
            'body_text' => $template->body_text,
            'editor' => $template->editor ?? Template::EDITOR_HTML,
            'design' => $template->design,
        ];

        if (isset($args['blocks']) || isset($args['html'])) {
            $data = array_merge($data, $this->content($args, $data['subject']));
        } elseif (isset($args['settings']) && $data['editor'] === Template::EDITOR_BLOCKS && is_array($data['design'])) {
            // только настройки оформления: пересобираем HTML из сохранённых блоков
            $data = array_merge($data, $this->content(['blocks' => $data['design']['blocks'] ?? [], 'settings' => array_merge((array) ($data['design']['settings'] ?? []), (array) $args['settings'])], $data['subject']));
        } elseif (array_key_exists('text', $args)) {
            $data['body_text'] = $this->nullable($args['text']);
        }

        if ($data['editor'] !== Template::EDITOR_BLOCKS) {
            $data['design'] = null;
        }

        $template->update($this->validate($ctx, $data, $template->id));

        return $template->refresh()->load('folder');
    }

    /**
     * Несохранённое письмо (черновик) для просмотра и проверки.
     *
     * @param  array<string, mixed>  $args
     */
    public function draft(McpContext $ctx, array $args): Template
    {
        if (! isset($args['blocks']) && ! isset($args['html'])) {
            throw new ToolException('Укажите письмо (template) или черновик: subject и blocks либо html');
        }

        $subject = (string) ($args['subject'] ?? '');
        $template = new Template(array_merge([
            'organization_id' => $ctx->organization->id,
            'name' => 'Черновик',
            'slug' => 'draft',
            'subject' => $subject,
            'preheader' => $this->nullable($args['preheader'] ?? null),
        ], $this->content($args, $subject)));
        $template->setRelation('senderAddress', null);

        return $template;
    }

    /**
     * HTML и текст письма из блоков или из готового HTML.
     *
     * @param  array<string, mixed>  $args
     * @return array{body_html: string, body_text: string|null, editor: string, design: array<string, mixed>|null}
     */
    private function content(array $args, string $subject): array
    {
        if (isset($args['blocks']) && isset($args['html'])) {
            throw new ToolException('Передайте что-то одно: blocks или html');
        }

        if (isset($args['blocks'])) {
            if (! is_array($args['blocks'])) {
                throw new ToolException('blocks — массив блоков визуального редактора (см. get_block_schema)');
            }

            // можно передать и весь design целиком: {version, settings, blocks}
            $blocks = array_is_list($args['blocks']) ? $args['blocks'] : (array) ($args['blocks']['blocks'] ?? []);
            $settings = array_merge(
                array_is_list($args['blocks']) ? [] : (array) ($args['blocks']['settings'] ?? []),
                is_array($args['settings'] ?? null) ? $args['settings'] : [],
            );

            if ($blocks === []) {
                throw new ToolException('В письме нет ни одного блока');
            }

            $design = $this->compiler->normalize($blocks, $settings);
            $html = $this->compiler->toHtml($design, $subject);

            return [
                'body_html' => $html,
                'body_text' => filled($args['text'] ?? null) ? (string) $args['text'] : ($this->compiler->htmlToText($html) ?: null),
                'editor' => Template::EDITOR_BLOCKS,
                'design' => $design,
            ];
        }

        $html = (string) $args['html'];

        if (trim($html) === '') {
            throw new ToolException('HTML письма пустой');
        }

        return [
            'body_html' => $html,
            'body_text' => filled($args['text'] ?? null) ? (string) $args['text'] : ($this->compiler->htmlToText($html) ?: null),
            'editor' => Template::EDITOR_HTML,
            'design' => null,
        ];
    }

    /**
     * Те же правила и сообщения, что у формы письма в админке.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(McpContext $ctx, array $data, ?int $templateId): array
    {
        $request = TemplateRequest::create('/', $templateId === null ? 'POST' : 'PUT', $data);
        $request->attributes->set('sender_organization', $ctx->organization);
        $route = (new Route($templateId === null ? 'POST' : 'PUT', 'templates/{template?}', []))->bind($request);

        if ($templateId !== null) {
            $route->setParameter('template', (string) $templateId);
        }

        $request->setRouteResolver(fn (): Route => $route);

        $validator = Validator::make($data, $request->rules(), $request->messages());

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();

            throw new ToolException('Письмо не сохранено: '.implode(' ', $validator->errors()->all()), $errors);
        }

        return array_merge($validator->validated(), [
            'body_text' => $data['body_text'] ?? null,
            'design' => $data['design'] ?? null,
            'editor' => $data['editor'] ?? Template::EDITOR_HTML,
        ]);
    }

    /**
     * Папка по ID или по названию; если папки с таким названием нет, она создаётся.
     *
     * @param  array<string, mixed>  $args
     */
    private function folderId(McpContext $ctx, array $args): ?int
    {
        if (isset($args['folder_id'])) {
            $folder = $ctx->organization->templateFolders()->find((int) $args['folder_id']);

            if ($folder === null) {
                throw new ToolException("Папка с ID {$args['folder_id']} не найдена");
            }

            return $folder->id;
        }

        $name = trim((string) ($args['folder'] ?? ''));

        if ($name === '') {
            return null;
        }

        $existing = $ctx->organization->templateFolders()->get()
            ->first(fn (TemplateFolder $folder): bool => Str::lower($folder->name) === Str::lower($name));

        if ($existing !== null) {
            return $existing->id;
        }

        if (mb_strlen($name) > 120) {
            throw new ToolException('Название папки: не длиннее 120 символов');
        }

        return $ctx->organization->templateFolders()->create(['name' => $name])->id;
    }

    private function uniqueSlug(McpContext $ctx, string $name): string
    {
        $base = Str::limit(Str::slug($name, '-', 'ru') ?: 'letter', 90, '');
        $slug = $base;
        $i = 2;

        while ($ctx->organization->templates()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function nullable(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
