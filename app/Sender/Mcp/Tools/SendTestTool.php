<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\TemplateVariables;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Services\MessageService;

class SendTestTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.templates.test';

    /**
     * Сколько тестовых писем в час можно отправить через ассистента.
     */
    public const PER_HOUR = 10;

    public function __construct(
        private readonly MessageService $messages,
        private readonly TemplateVariables $variables,
    ) {}

    public function name(): string
    {
        return 'send_test';
    }

    public function title(): string
    {
        return 'Отправить тест себе';
    }

    public function description(): string
    {
        return 'Отправляет сохранённое письмо тестом на почту владельца ключа (только себе, другим адресатам нельзя) — так же, как кнопка «Тест» в редакторе. '
            .'Переменные — примеры и значения организации, можно перекрыть data. Не больше '.self::PER_HOUR.' писем в час. '
            .'Sends a test of a saved letter to the key owner\'s own email.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template' => Schemas::templateRef(),
                'data' => ['type' => 'object', 'description' => 'Значения переменных для теста.'],
            ],
            'required' => ['template'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireContentEditing();
        $template = $ctx->template($args['template']);
        $domain = $ctx->organization->domains()->where('status', Domain::STATUS_VERIFIED)->oldest('id')->first();

        if ($domain === null) {
            throw new ToolException('Нет подтверждённого домена: тестовое письмо отправить не с чего');
        }

        $ctx->throttleTestLetters(self::PER_HOUR);

        $message = $this->messages->send(
            $ctx->organization,
            $template,
            $ctx->user->email,
            $this->variables->sampleData($ctx->organization, is_array($args['data'] ?? null) ? $args['data'] : []),
        );

        if ($message->status === Message::STATUS_BLOCKED) {
            throw new ToolException('Тестовое письмо не отправлено: '.$message->error);
        }

        // в журнал попадает только действительно отправленный тест
        $ctx->audit(self::AUDIT_ACTION, $template->name);

        return [
            'message' => "Тестовое письмо «{$template->name}» поставлено в очередь на {$ctx->user->email}",
            'to' => $ctx->user->email,
            'message_uuid' => $message->uuid,
            'status' => $message->status,
        ];
    }
}
