<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Event;
use App\Models\Speaker;

/**
 * Полностью задаёт список спикеров мероприятия: роль, тема выступления и порядок.
 */
class SetEventSpeakersTool implements Tool
{
    public function name(): string
    {
        return 'set_event_speakers';
    }

    public function title(): string
    {
        return 'Спикеры мероприятия';
    }

    public function description(): string
    {
        return 'Задаёт полный список спикеров мероприятия (прежний заменяется). У каждого: id спикера, роль, тема выступления и порядок. Пустой список снимает всех спикеров.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'speakers' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID спикера'],
                            'role' => ['type' => ['string', 'null'], 'maxLength' => 100, 'description' => 'Роль, например «Лектор»'],
                            'topic' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'Тема выступления'],
                            'sort_order' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['event_id', 'speakers'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $event = Event::query()->find($args['event_id']) ?? throw new ToolException("Не найдено: мероприятие с id {$args['event_id']}");

        $ids = array_column($args['speakers'], 'id');
        $missing = array_diff($ids, Speaker::query()->whereIn('id', $ids)->pluck('id')->all());
        if ($missing !== []) {
            throw new ToolException('Не найдены спикеры с id: '.implode(', ', $missing));
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new ToolException('Один спикер указан несколько раз');
        }

        $sync = [];
        foreach (array_values($args['speakers']) as $i => $s) {
            $sync[$s['id']] = ['role' => $s['role'] ?? null, 'topic' => $s['topic'] ?? null, 'sort_order' => $s['sort_order'] ?? $i];
        }
        $event->speakers()->sync($sync);
        $ctx->audit($this->name(), 'update', 'event', $event->id, ['speakers' => $ids]);

        return ['event_id' => $event->id, 'speakers' => $event->speakers()->orderBy('event_speaker.sort_order')->get()->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->full_name, 'role' => $s->pivot->role, 'topic' => $s->pivot->topic, 'sort_order' => $s->pivot->sort_order,
        ])->all()];
    }
}
