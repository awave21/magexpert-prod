<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Http\Requests\Api\UpsertContactRequest;
use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Tool;
use App\Sender\Mcp\ToolException;
use App\Sender\Services\ContactUpsertService;

class UpsertContactsTool implements Tool
{
    public const AUDIT_ACTION = 'mcp.contacts.upsert';

    /**
     * Столько же, сколько в пакетной загрузке открытого API.
     */
    public const MAX = 1000;

    public function __construct(private readonly ContactUpsertService $service) {}

    public function name(): string
    {
        return 'upsert_contacts';
    }

    public function title(): string
    {
        return 'Добавить или изменить подписчиков';
    }

    public function description(): string
    {
        return 'Добавляет подписчиков в базу или меняет существующих (по адресу), до '.self::MAX.' за раз — так же, как открытый API Sender. '
            .'name и data (дополнительные поля, переменные в письме) объединяются с сохранёнными, null в поле data удаляет его. '
            .'subscribed=false отписывает адрес, true возвращает подписку. Если база требует подтверждения, новым адресам уходит письмо со ссылкой. '
            .'Адреса из блок-листа организации (отказы, жалобы) писем не получают в любом случае. Строки с ошибками возвращаются в invalid, остальные применяются. '
            .'Upserts contacts into a list.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list' => Schemas::listRef(),
                'contacts' => [
                    'type' => 'array',
                    'maxItems' => self::MAX,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => ['type' => 'string', 'maxLength' => 255],
                            'name' => ['type' => ['string', 'null'], 'maxLength' => 250],
                            'data' => ['type' => ['object', 'null'], 'description' => 'Поля: ключ латиницей (a-z, 0-9, _), значение — строка, число, true/false или null.'],
                            'subscribed' => ['type' => 'boolean', 'description' => 'false — отписать, true — подписан (по умолчанию).'],
                        ],
                        'required' => ['email'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['list', 'contacts'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $ctx->requireDataEditing();
        $list = $ctx->list($args['list']);

        if ($list->isSynced()) {
            throw new ToolException("База «{$list->name}» обновляется из источника автоматически: менять подписчиков в ней нельзя");
        }

        if ($args['contacts'] === []) {
            throw new ToolException('Передайте хотя бы одного подписчика');
        }

        $valid = [];
        $invalid = [];

        foreach ($args['contacts'] as $index => $row) {
            [$data, $errors] = UpsertContactRequest::validateRow($row);

            if ($data === null) {
                $invalid[] = ['index' => $index, 'email' => is_string($row['email'] ?? null) ? mb_strtolower(trim($row['email'])) : null, 'errors' => $errors];
            } else {
                $valid[] = $data;
            }
        }

        $totals = $this->service->batch($list, $valid);

        if ($valid !== []) {
            $ctx->audit(self::AUDIT_ACTION, $list->name.': '.count($valid));
        }

        return [
            'message' => 'База «'.$list->name.'»: добавлено '.$totals['created'].', обновлено '.$totals['updated']
                .($invalid !== [] ? ', с ошибками '.count($invalid) : ''),
            'list' => ['id' => $list->id, 'name' => $list->name],
            ...$totals,
            'invalid' => $invalid,
        ];
    }
}
