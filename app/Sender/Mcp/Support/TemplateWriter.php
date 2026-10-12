<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Http\Requests\Admin\TemplateRequest;
use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Template;
use App\Sender\Models\TemplateFolder;
use App\Sender\Support\HtmlSanitizer;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Создание и правка письма ассистентом по тем же правилам, что и в админке (TemplateRequest).
 * Содержимое — блоки (сервер соберёт HTML, письмо откроется в визуальном редакторе) или готовый HTML.
 */
class TemplateWriter
{
    public function __construct(
        private readonly BlockCompiler $compiler,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

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
        ];
        $folder = $this->folder($ctx, $args);
        $data['folder_id'] = $folder['id'];

        if (! isset($args['blocks']) && ! isset($args['html'])) {
            throw new ToolException('Передайте содержимое письма: blocks (блоки визуального редактора) или html');
        }

        $data = array_merge($data, $this->content($args, $data['subject']));
        $validated = $this->validate($ctx, $data, null);

        // новая папка появляется только вместе с письмом: при ошибке не остаётся пустых папок
        return $ctx->organization->getConnection()->transaction(function () use ($ctx, $folder, $validated): Template {
            $validated['folder_id'] = $this->createFolderIfNeeded($ctx, $folder);

            return $ctx->organization->templates()->create($validated)->load('folder');
        });
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
            'folder_id' => $template->folder_id,
            'body_html' => $template->body_html,
            'body_text' => $template->body_text,
            'editor' => $template->editor ?? Template::EDITOR_HTML,
            'design' => $template->design,
        ];

        $folder = array_key_exists('folder', $args) || array_key_exists('folder_id', $args) ? $this->folder($ctx, $args) : ['id' => $template->folder_id, 'create' => null];
        $data['folder_id'] = $folder['id'];

        // заготовки картинок без адреса, сохранённые редактором, не мешают правке
        $storedEmptyImages = is_array($template->design) ? BlockCompiler::emptyImageIds((array) ($template->design['blocks'] ?? [])) : [];

        if (isset($args['blocks']) || isset($args['html'])) {
            $data = array_merge($data, $this->content($args, $data['subject'], $storedEmptyImages));
        } elseif (isset($args['settings']) && $data['editor'] === Template::EDITOR_BLOCKS && is_array($data['design'])) {
            // только настройки оформления: пересобираем HTML из сохранённых блоков
            $data = array_merge($data, $this->content(['blocks' => $data['design']['blocks'] ?? [], 'settings' => array_merge((array) ($data['design']['settings'] ?? []), (array) $args['settings'])], $data['subject'], $storedEmptyImages));
        } elseif (array_key_exists('text', $args)) {
            $data['body_text'] = $this->nullable($args['text']);
        }

        if ($data['editor'] !== Template::EDITOR_BLOCKS) {
            $data['design'] = null;
        }

        $validated = $this->validate($ctx, $data, $template->id);

        $ctx->organization->getConnection()->transaction(function () use ($ctx, $template, $folder, $validated): void {
            $validated['folder_id'] = $this->createFolderIfNeeded($ctx, $folder);
            $template->update($validated);
        });

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
     * HTML ассистента (и в блоках, и готовый) очищается от скриптов, обработчиков и ссылок javascript:.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $storedEmptyImages  id сохранённых картинок-заготовок без адреса
     * @return array{body_html: string, body_text: string|null, editor: string, design: array<string, mixed>|null}
     */
    private function content(array $args, string $subject, array $storedEmptyImages = []): array
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

            $design = $this->compiler->normalize($blocks, $settings, $storedEmptyImages);
            $html = $this->compiler->toHtml($design, $subject);

            return [
                'body_html' => $html,
                'body_text' => filled($args['text'] ?? null) ? (string) $args['text'] : ($this->compiler->htmlToText($html) ?: null),
                'editor' => Template::EDITOR_BLOCKS,
                'design' => $design,
            ];
        }

        $html = $this->sanitizer->clean((string) $args['html']);

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
     * Папка по ID или по названию. Если папки с таким названием нет, возвращается её название (create):
     * она создаётся только после проверки письма, в одной транзакции с ним.
     *
     * @param  array<string, mixed>  $args
     * @return array{id: int|null, create: string|null}
     */
    private function folder(McpContext $ctx, array $args): array
    {
        if (isset($args['folder_id'])) {
            $folder = $ctx->organization->templateFolders()->find((int) $args['folder_id']);

            if ($folder === null) {
                throw new ToolException("Папка с ID {$args['folder_id']} не найдена");
            }

            return ['id' => $folder->id, 'create' => null];
        }

        $name = trim((string) ($args['folder'] ?? ''));

        if ($name === '') {
            return ['id' => null, 'create' => null];
        }

        $existing = $this->findFolder($ctx, $name);

        if ($existing !== null) {
            return ['id' => $existing->id, 'create' => null];
        }

        if (mb_strlen($name) > 120) {
            throw new ToolException('Название папки: не длиннее 120 символов');
        }

        return ['id' => null, 'create' => $name];
    }

    /**
     * @param  array{id: int|null, create: string|null}  $folder
     */
    private function createFolderIfNeeded(McpContext $ctx, array $folder): ?int
    {
        if ($folder['create'] === null) {
            return $folder['id'];
        }

        return ($this->findFolder($ctx, $folder['create']) ?? $ctx->organization->templateFolders()->create(['name' => $folder['create']]))->id;
    }

    private function findFolder(McpContext $ctx, string $name): ?TemplateFolder
    {
        return $ctx->organization->templateFolders()->get()
            ->first(fn (TemplateFolder $folder): bool => Str::lower($folder->name) === Str::lower($name));
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
