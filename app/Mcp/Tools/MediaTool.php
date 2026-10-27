<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Support\MediaSource;
use App\Mcp\Support\MediaStore;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\MedicalLibrary;

/**
 * Загрузка и удаление картинок и документа: set_image, remove_image, set_library_file.
 */
class MediaTool implements Tool
{
    public function __construct(private readonly string $kind, private readonly MediaStore $store = new MediaStore) {}

    public function name(): string
    {
        return $this->kind;
    }

    public function title(): string
    {
        return match ($this->kind) {
            'set_image' => 'Загрузить картинку',
            'remove_image' => 'Убрать картинку',
            'set_library_file' => 'Загрузить документ в библиотеку',
        };
    }

    public function description(): string
    {
        $targets = implode(', ', array_map(fn ($k, $v) => "{$k} — {$v['label']}", array_keys(MediaStore::TARGETS), MediaStore::TARGETS));

        return match ($this->kind) {
            'set_image' => "Загружает картинку из ссылки https или base64 (до 15 МБ; jpeg, png, gif, webp; сохраняется как webp). Что загрузить (target): {$targets}. Прежняя картинка заменяется.",
            'remove_image' => "Убирает картинку; без неё на сайте покажется обложка по умолчанию. target: {$targets}. Для логотипа партнёра и обложки не нужны подтверждения, но логотип партнёра убрать нельзя.",
            'set_library_file' => 'Загружает документ материала библиотеки (pdf, doc, docx, xls, xlsx, ppt, pptx, txt; до 20 МБ) из ссылки https или base64. Прежний файл заменяется; новый документ попадёт в индекс ИИ-помощника.',
        };
    }

    public function schema(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'ID записи'];
        $target = ['type' => 'string', 'enum' => array_keys(MediaStore::TARGETS)];

        return match ($this->kind) {
            'set_image' => [
                'type' => 'object',
                'properties' => ['target' => $target, 'id' => $id, 'source' => MediaSource::schema('Откуда взять картинку.')],
                'required' => ['target', 'id', 'source'],
                'additionalProperties' => false,
            ],
            'remove_image' => [
                'type' => 'object',
                'properties' => ['target' => $target, 'id' => $id],
                'required' => ['target', 'id'],
                'additionalProperties' => false,
            ],
            'set_library_file' => [
                'type' => 'object',
                'properties' => ['id' => $id, 'source' => MediaSource::schema('Откуда взять документ; filename обязателен, если загружаете base64 или в ссылке нет расширения.')],
                'required' => ['id', 'source'],
                'additionalProperties' => false,
            ],
        };
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => $this->kind === 'remove_image', 'idempotentHint' => true, 'openWorldHint' => $this->kind !== 'remove_image'];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        if ($this->kind === 'set_library_file') {
            $item = MedicalLibrary::query()->find($args['id']) ?? throw new ToolException("Не найден материал с id {$args['id']}");
            $path = $this->store->saveLibraryFile($item, $args['source']);
            $ctx->audit('set_library_file', 'update', 'library', $item->id, ['file' => $path]);

            return ['id' => $item->id, 'file_path' => $path];
        }

        $target = $args['target'];
        $model = $this->store->find($target, (int) $args['id']);

        if ($this->kind === 'set_image') {
            $value = $this->store->saveImage($target, $model, $args['source']);
            $ctx->audit('set_image', 'update', $target, $model->id, ['path' => $value]);

            return ['target' => $target, 'id' => $model->id, 'path' => $value];
        }

        if ($target === 'partner_logo') {
            throw new ToolException('Логотип партнёра обязателен: загрузите новый через set_image вместо удаления.');
        }

        $this->store->removeImage($target, $model);
        $column = MediaStore::TARGETS[$target]['column'];
        $model->{$column} = null;
        $model->save();
        $ctx->audit('remove_image', 'update', $target, $model->id);

        return ['target' => $target, 'id' => $model->id, 'removed' => true];
    }
}
