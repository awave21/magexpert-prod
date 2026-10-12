<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplateVariables;
use App\Sender\Mcp\Tool;
use App\Sender\Models\Variable;
use App\Sender\Services\BaseVariables;
use stdClass;

class ListVariablesTool implements Tool
{
    public function __construct(private readonly TemplateVariables $variables) {}

    public function name(): string
    {
        return 'list_variables';
    }

    public function title(): string
    {
        return 'Переменные для писем';
    }

    public function description(): string
    {
        return 'Переменные, которые можно вставлять в письма как {{ key }} или условием {{#if key}}...{{/if}}: базовые (имя, мероприятие, даты, ссылка отписки), '
            .'переменные организации (с общими значениями) и поля подписчиков из баз — с описанием и примером. Lists variables usable in letters.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $fields = $this->variables->contactFields($ctx->organization);

        return [
            'syntax' => '{{ key }} — значение (в HTML экранируется), {{#if key}}…{{/if}} — фрагмент, только если значение заполнено. Неизвестная переменная заменяется пустой строкой.',
            'base' => BaseVariables::all(),
            'organization' => $ctx->organization->variables()->orderBy('key')->get()->map(fn (Variable $v): array => [
                'key' => $v->key,
                'label' => $v->label,
                'default_value' => $v->default_value,
            ])->values()->all(),
            'contact_fields' => collect($fields)->map(fn (string $sample, int|string $key): array => [
                'key' => (string) $key,
                'sample' => $sample,
                'description' => 'Поле подписчика из базы: заполнено только в рассылках по базам, где есть эта колонка',
            ])->values()->all(),
        ];
    }
}
