<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplatePresenter;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Template;
use App\Sender\Models\TemplateFolder;
use Illuminate\Support\Str;

class ListTemplatesTool implements Tool
{
    public function __construct(private readonly TemplatePresenter $presenter) {}

    public function name(): string
    {
        return 'list_templates';
    }

    public function title(): string
    {
        return 'Список писем';
    }

    public function description(): string
    {
        return 'Письма (шаблоны) организации: ID, идентификатор, название, папка, тема, прехедер, использованные переменные, дата изменения, '
            .'собрано ли в визуальном редакторе. Без HTML — его даёт get_template. Фильтры: папка, поиск по названию/идентификатору/теме, лимит. '
            .'Lists organization letters (templates) with metadata.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'folder' => ['type' => ['integer', 'string'], 'description' => 'Папка: ID или название. "none" — письма без папки.'],
                'query' => ['type' => 'string', 'description' => 'Поиск по названию, идентификатору или теме.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'Сколько писем вернуть (новые первыми).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $query = $ctx->organization->templates()->with('folder')->latest('updated_at');
        $folder = $args['folder'] ?? null;

        if ($folder === 'none') {
            $query->whereNull('folder_id');
        } elseif ($folder !== null && $folder !== '') {
            $model = is_int($folder) || ctype_digit((string) $folder)
                ? $ctx->organization->templateFolders()->find((int) $folder)
                : $ctx->organization->templateFolders()->get()->first(fn (TemplateFolder $f): bool => Str::lower($f->name) === Str::lower(trim((string) $folder)));

            if ($model === null) {
                throw new ToolException("Папка «{$folder}» не найдена. Список папок — list_folders.");
            }

            $query->where('folder_id', $model->id);
        }

        if (filled($args['query'] ?? null)) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $args['query'])).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('subject', 'like', $like));
        }

        $total = (clone $query)->count();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $templates = $query->limit($limit)->get();

        return [
            'total' => $total,
            'returned' => $templates->count(),
            'templates' => $templates->map(fn (Template $t): array => $this->presenter->summary($t))->values()->all(),
        ];
    }
}
