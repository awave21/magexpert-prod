<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\BlockCompiler;
use App\Sender\Mcp\Support\TemplateVariables;
use App\Sender\Mcp\Support\TemplateWriter;
use App\Sender\Mcp\Tool;
use App\Sender\Services\TemplateRenderer;

class RenderTemplateTool implements Tool
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly TemplateWriter $writer,
        private readonly TemplateVariables $variables,
        private readonly BlockCompiler $compiler,
    ) {}

    public function name(): string
    {
        return 'render_template';
    }

    public function title(): string
    {
        return 'Собрать письмо с данными';
    }

    public function description(): string
    {
        return 'Показывает, каким письмо придёт получателю: подставляет переменные и возвращает тему, полный HTML и текстовую версию. '
            .'Письмо — сохранённое (template) или несохранённый черновик (subject + blocks или html). '
            .'Данные: data, иначе первый подписчик базы list_id, иначе примеры базовых переменных и значения переменных организации. '
            .'Ничего не сохраняет и не отправляет. Renders a saved letter or a draft with sample data.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'template' => Schemas::templateRef(),
                'subject' => ['type' => 'string', 'description' => 'Тема черновика (если template не указан).'],
                'preheader' => ['type' => 'string', 'description' => 'Прехедер черновика.'],
                'data' => ['type' => 'object', 'description' => 'Значения переменных, например {"first_name": "Анна"}. Перекрывают остальные источники.'],
                'list_id' => ['type' => 'integer', 'description' => 'ID базы подписчиков: взять переменные первого подписчика.'],
            ], Schemas::content()),
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $template = isset($args['template']) ? $ctx->template($args['template']) : $this->writer->draft($ctx, $args);
        $data = $this->variables->sampleData(
            $ctx->organization,
            is_array($args['data'] ?? null) ? $args['data'] : [],
            isset($args['list_id']) ? (int) $args['list_id'] : null,
        );

        $rendered = $this->renderer->render($template, $data);
        $used = $this->variables->used($template->subject, (string) $template->preheader, (string) $template->body_html, (string) $template->body_text);

        return [
            'subject' => $rendered['subject'],
            'preheader' => $template->preheader,
            'html' => $rendered['html'],
            'text' => $rendered['text'] ?? $this->compiler->htmlToText($rendered['html']),
            'variables_used' => $used,
            'variables_empty' => array_values(array_filter($used, fn (string $key): bool => blank(data_get($data, $key)))),
            'html_size_kb' => round(strlen($rendered['html']) / 1024, 1),
        ];
    }
}
