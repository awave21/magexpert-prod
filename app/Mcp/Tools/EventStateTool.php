<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Event;

/**
 * Публикация и скрытие мероприятия на сайте.
 */
class EventStateTool implements Tool
{
    public function __construct(private readonly bool $publish) {}

    public function name(): string
    {
        return $this->publish ? 'publish_event' : 'unpublish_event';
    }

    public function title(): string
    {
        return $this->publish ? 'Опубликовать мероприятие' : 'Скрыть мероприятие';
    }

    public function description(): string
    {
        return $this->publish
            ? 'Показывает мероприятие на сайте (is_active = true). Перед публикацией проверьте, что заполнены название, дата, тип и описание.'
            : 'Скрывает мероприятие с сайта (is_active = false), данные сохраняются.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID мероприятия']],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $event = Event::query()->find($args['id']) ?? throw new ToolException("Не найдено: мероприятие с id {$args['id']}");
        $event->update(['is_active' => $this->publish]);
        $ctx->audit($this->name(), $this->publish ? 'publish' : 'unpublish', 'event', $event->id, ['title' => $event->title]);

        return ['id' => $event->id, 'title' => $event->title, 'is_active' => $this->publish];
    }
}
