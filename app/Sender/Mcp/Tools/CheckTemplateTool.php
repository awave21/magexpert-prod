<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplateLinter;
use App\Sender\Mcp\Support\TemplateWriter;
use App\Sender\Mcp\Tool;
use App\Sender\Models\Template;

class CheckTemplateTool implements Tool
{
    public function __construct(
        private readonly TemplateLinter $linter,
        private readonly TemplateWriter $writer,
    ) {}

    public function name(): string
    {
        return 'check_template';
    }

    public function title(): string
    {
        return 'Проверить письмо';
    }

    public function description(): string
    {
        return 'Проверка письма перед отправкой: ссылка отписки в рассылке, пустые/небезопасные/javascript-ссылки, картинки без alt, '
            .'вес HTML (Gmail обрезает после ~100 КБ), длина темы, заглавные буквы и спам-слова в теме, прехедер, неизвестные переменные, адрес отправителя. '
            .'Возвращает список замечаний с уровнем error / warning / info. Lints a saved letter or a draft.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'template' => Schemas::templateRef(),
                'kind' => ['type' => 'string', 'enum' => ['auto', 'campaign', 'transactional'], 'default' => 'auto', 'description' => 'campaign — письмо рассылки (нужна ссылка отписки), transactional — служебное письмо сайта. auto: служебные письма сайта определяются по идентификатору.'],
                'subject' => ['type' => 'string', 'description' => 'Тема черновика (если template не указан).'],
                'preheader' => ['type' => 'string', 'description' => 'Прехедер черновика.'],
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
        $saved = isset($args['template']) ? $ctx->template($args['template']) : null;
        $template = $saved ?? $this->writer->draft($ctx, $args);
        $kind = $this->kind((string) ($args['kind'] ?? 'auto'), $saved);

        $findings = $this->linter->check($ctx->organization, [
            'subject' => (string) $template->subject,
            'preheader' => $template->preheader,
            'html' => (string) $template->body_html,
            'text' => $template->body_text,
        ], $kind, $saved);

        $counts = array_count_values(array_column($findings, 'severity'));

        return [
            'kind' => $kind,
            'ok' => ($counts[TemplateLinter::ERROR] ?? 0) === 0,
            'summary' => sprintf('Ошибок: %d, предупреждений: %d, советов: %d', $counts[TemplateLinter::ERROR] ?? 0, $counts[TemplateLinter::WARNING] ?? 0, $counts[TemplateLinter::INFO] ?? 0),
            'findings' => $findings,
        ];
    }

    /**
     * Служебные письма сайта — те, чьи идентификаторы указаны в настройках клиента Sender.
     */
    private function kind(string $kind, ?Template $template): string
    {
        if ($kind !== 'auto') {
            return $kind;
        }

        $system = array_map('strval', array_values((array) config('sender.client.templates', [])));

        return $template !== null && (in_array($template->slug, $system, true) || in_array((string) $template->id, $system, true))
            ? 'transactional'
            : 'campaign';
    }
}
