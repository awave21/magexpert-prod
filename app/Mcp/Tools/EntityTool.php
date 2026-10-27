<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Support\Entities;
use App\Mcp\Support\MediaSource;
use App\Mcp\Support\MediaStore;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * Универсальный инструмент над сущностью: list, get, create, update, delete.
 */
class EntityTool implements Tool
{
    public const ACTIONS = ['list', 'get', 'create', 'update', 'delete'];

    /**
     * @var array<string, mixed>
     */
    private array $def;

    public function __construct(private readonly string $entity, private readonly string $action)
    {
        $this->def = Entities::get($entity);
    }

    public function name(): string
    {
        return match ($this->action) {
            'list' => 'list_'.$this->plural(),
            default => $this->action.'_'.$this->entity,
        };
    }

    private function plural(): string
    {
        return match ($this->entity) {
            'category' => 'categories',
            'library' => 'library',
            default => $this->entity.'s',
        };
    }

    public function title(): string
    {
        $s = $this->def['singular'];

        return match ($this->action) {
            'list' => 'Список: '.$this->def['plural'],
            'get' => 'Показать: '.$s,
            'create' => 'Создать: '.$s,
            'update' => 'Изменить: '.$s,
            'delete' => 'Удалить: '.$s,
        };
    }

    public function description(): string
    {
        $s = $this->def['singular'];

        return match ($this->action) {
            'list' => "Список: {$this->def['plural']}. Поиск по тексту q, постранично (limit до 100, page).",
            'get' => "Полная карточка: {$s} по id.",
            'create' => "Создаёт: {$s}."
                .(isset($this->def['media_create']) ? ' Файлы передаются в полях '.implode(', ', array_keys($this->def['media_create'])).' (url https или base64).' : ' Картинки загружаются отдельно через set_image.'),
            'update' => "Меняет только переданные поля: {$s}. Чтобы очистить поле, передайте null.",
            'delete' => "Удаляет: {$s} безвозвратно. Нужен confirm: true; только администратор и менеджер. Сначала покажите пользователю, что будет удалено.",
        };
    }

    public function schema(): array
    {
        $fields = array_map(function (array $f): array {
            return $f[0] + ['description' => $f[2]];
        }, $this->def['fields']);

        $id = ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID']];

        return match ($this->action) {
            'list' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Поиск по тексту'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                ] + array_map(fn (array $f): array => $f[0] + ['description' => $f[1]], $this->def['filters']),
                'additionalProperties' => false,
            ],
            'get' => ['type' => 'object', 'properties' => $id, 'required' => ['id'], 'additionalProperties' => false],
            'create' => [
                'type' => 'object',
                'properties' => $fields + array_map(fn (array $m): array => MediaSource::schema($m['label'].'.'), $this->def['media_create'] ?? []),
                'required' => array_merge(
                    array_keys(array_filter($this->def['fields'], fn ($f) => str_starts_with($f[1], 'required'))),
                    array_keys(array_filter($this->def['media_create'] ?? [], fn ($m) => $m['required'])),
                ),
                'additionalProperties' => false,
            ],
            'update' => ['type' => 'object', 'properties' => $id + $fields, 'required' => ['id'], 'additionalProperties' => false],
            'delete' => [
                'type' => 'object',
                'properties' => $id + ['confirm' => ['type' => 'boolean', 'description' => 'Должно быть true']],
                'required' => ['id', 'confirm'],
                'additionalProperties' => false,
            ],
        };
    }

    public function annotations(): array
    {
        return [
            'readOnlyHint' => in_array($this->action, ['list', 'get'], true),
            'destructiveHint' => $this->action === 'delete',
            'idempotentHint' => in_array($this->action, ['list', 'get', 'update', 'delete'], true),
            'openWorldHint' => false,
        ];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        return match ($this->action) {
            'list' => $this->list($args),
            'get' => ['item' => $this->present($this->find((int) $args['id']))],
            'create' => $this->create($args, $ctx),
            'update' => $this->update($args, $ctx),
            'delete' => $this->delete($args, $ctx),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function list(array $args): array
    {
        $model = $this->def['model'];
        $q = $model::query()->with($this->def['with']);

        if (($term = trim((string) ($args['q'] ?? ''))) !== '') {
            $variants = array_unique([$term, mb_strtolower($term), mb_convert_case($term, MB_CASE_TITLE), mb_strtoupper($term)]);
            $q->where(function ($w) use ($variants): void {
                foreach ($this->def['search'] as $column) {
                    foreach ($variants as $v) {
                        $w->orWhere($column, 'like', '%'.addcslashes($v, '%_\\').'%');
                    }
                }
            });
        }

        if (isset($this->def['apply_filters'])) {
            ($this->def['apply_filters'])($q, $args);
        }

        [$col, $dir] = $this->def['order'];
        $limit = (int) ($args['limit'] ?? 20);
        $page = $q->orderBy($col, $dir)->orderByDesc('id')->paginate($limit, ['*'], 'page', (int) ($args['page'] ?? 1));

        return [
            'items' => $page->getCollection()->map(fn (Model $m): array => $this->present($m))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args, McpContext $ctx): array
    {
        $media = array_intersect_key($args, $this->def['media_create'] ?? []);
        $args = array_diff_key($args, $media);
        $data = $this->validated($args, null);
        $this->extra($data, null);
        $related = array_intersect_key($data, array_flip(['categories']));
        $data = array_diff_key($data, $related);

        if (isset($this->def['before_create'])) {
            ($this->def['before_create'])($data);
        }

        $store = new MediaStore;
        foreach ($media as $key => $source) {
            if ($this->def['media_create'][$key]['target'] === 'library_file') {
                MediaSource::fetch($source, MediaSource::MAX_FILE);
            } else {
                MediaSource::fetch($source, MediaSource::MAX_IMAGE);
            }
        }

        $item = $this->def['model']::query()->create($data);

        try {
            foreach ($media as $key => $source) {
                $target = $this->def['media_create'][$key]['target'];
                $target === 'library_file' ? $store->saveLibraryFile($item, $source) : $store->saveImage($target, $item, $source);
            }
        } catch (ToolException $e) {
            $item->delete();
            throw $e;
        }

        $this->afterSave($item, $related);
        $ctx->audit($this->name(), 'create', $this->entity, $item->id, ['fields' => array_keys($args)]);

        return ['item' => $this->present($this->find($item->id))];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function update(array $args, McpContext $ctx): array
    {
        $item = $this->find((int) $args['id']);
        unset($args['id']);

        $data = $this->validated($args, $item);
        $merged = array_merge($item->getAttributes(), $this->present($item), $data);
        $this->extra($merged, $item);

        $related = array_intersect_key($data, array_flip(['categories']));
        $data = array_diff_key($data, $related);

        if (isset($data['slug']) && $data['slug'] === '') {
            unset($data['slug']);
        }

        $item->update($data);
        $this->afterSave($item, $related);
        $ctx->audit($this->name(), 'update', $this->entity, $item->id, ['fields' => array_keys($args)]);

        return ['item' => $this->present($this->find($item->id))];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function delete(array $args, McpContext $ctx): array
    {
        if (($args['confirm'] ?? false) !== true) {
            throw new ToolException('Удаление не подтверждено: передайте confirm: true после согласия пользователя.');
        }
        if (! $ctx->canDelete()) {
            throw new ToolException('Удалять могут только администратор и менеджер.');
        }

        $item = $this->find((int) $args['id']);
        $label = $this->label($item);

        if (isset($this->def['delete_guard']) && ($reason = ($this->def['delete_guard'])($item))) {
            throw new ToolException($reason);
        }

        if (isset($this->def['on_delete'])) {
            ($this->def['on_delete'])($item);
        }

        $id = $item->id;
        $item->delete();
        $ctx->audit($this->name(), 'delete', $this->entity, $id, ['label' => $label]);

        return ['deleted' => true, 'id' => $id, 'label' => $label];
    }

    private function find(int $id): Model
    {
        return $this->def['model']::query()->with($this->def['with'])->find($id)
            ?? throw new ToolException("Не найдено: {$this->def['singular']} с id {$id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Model $m): array
    {
        return ($this->def['present'])($m);
    }

    private function label(Model $m): string
    {
        $p = $this->present($m);

        return (string) ($p['title'] ?? $p['name'] ?? $p['full_name'] ?? $m->getKey());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function validated(array $args, ?Model $existing): array
    {
        $rules = [];
        foreach ($this->def['fields'] as $name => $f) {
            $rule = $f[1];
            if ($existing !== null) {
                $rule = 'sometimes|'.$rule;
            }
            $rules[$name] = $rule;
        }
        $rules['tags.*'] = 'string|max:120';
        $rules['categories.*'] = 'integer|exists:categories,id';

        foreach ($this->def['unique'] ?? [] as $column) {
            $rules[$column] .= '|unique:'.(new $this->def['model'])->getTable().",{$column}".($existing ? ','.$existing->getKey() : '');
        }

        $validator = Validator::make($args, $rules);

        if ($validator->fails()) {
            throw new ToolException('Проверьте поля', $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function extra(array $merged, ?Model $existing): void
    {
        if (isset($this->def['validate_extra'])) {
            ($this->def['validate_extra'])($merged, $existing);
        }
    }

    /**
     * @param  array<string, mixed>  $related
     */
    private function afterSave(Model $item, array $related): void
    {
        if (isset($this->def['after_save'])) {
            ($this->def['after_save'])($item, $related);
        }
    }
}
