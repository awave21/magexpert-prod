<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Http\Requests\Admin\CampaignRequest;
use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\ToolException;
use App\Sender\Models\Campaign;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Аргументы ассистента → поля рассылки. Проверяются теми же правилами, что и форма рассылки в админке.
 */
class CampaignInput
{
    /**
     * Поля рассылки, которые ассистент задаёт как есть (письмо и база передаются ссылками template и list).
     */
    private const FIELDS = ['name', 'track', 'send_mode', 'batch_size', 'batch_interval', 'daily_limit', 'window_start', 'window_end', 'window_days'];

    /**
     * Пустые условия сегмента отбрасываются; ничего не осталось — вся база (null).
     *
     * @return array{active_days?: int, field?: string, values?: list<string>, tags?: list<string>}|null
     */
    public static function segment(mixed $segment): ?array
    {
        if (! is_array($segment)) {
            return null;
        }

        $clean = array_filter(
            Arr::only($segment, ['active_days', 'field', 'values', 'tags']),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        if (array_key_exists('field', $clean) !== array_key_exists('values', $clean)) {
            throw new ToolException('Для отбора по полю нужны и field, и values (поля и значения — list_fields)');
        }

        if (array_key_exists('tags', $clean)) {
            $tags = \App\Sender\Models\Contact::normalizeTags((array) $clean['tags']);

            foreach ($tags as $tag) {
                if (! preg_match(\App\Sender\Models\Contact::TAG_PATTERN, $tag)) {
                    throw new ToolException('Тег «'.$tag.'»: буквы, цифры, пробел и . _ : , - ( ) / +, до 120 символов');
                }
            }

            if ($tags === []) {
                unset($clean['tags']);
            } else {
                $clean['tags'] = $tags;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Проверенные поля новой рассылки или черновика $current с изменениями из $args.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function attributes(McpContext $ctx, array $args, ?Campaign $current = null): array
    {
        $data = $current?->only([...self::FIELDS, 'template_id', 'list_id', 'segment']) ?? [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = is_string($args[$field]) ? trim($args[$field]) : $args[$field];
            }
        }

        if (array_key_exists('template', $args)) {
            $data['template_id'] = $args['template'] === null ? null : $ctx->template($args['template'])->id;
        }

        if (array_key_exists('list', $args)) {
            $data['list_id'] = $args['list'] === null ? null : $ctx->list($args['list'])->id;
        }

        if (array_key_exists('segment', $args)) {
            $data['segment'] = self::segment($args['segment']);
        }

        $rules = Arr::except(CampaignRequest::campaignRules($ctx->organization->id), ['scheduled_at']);
        $validator = Validator::make($data, $rules, CampaignRequest::campaignMessages());

        if ($validator->fails()) {
            throw new ToolException('Рассылка заполнена с ошибками: '.implode('; ', $validator->errors()->all()), $validator->errors()->toArray());
        }

        return CampaignRequest::withoutDripSettings($validator->validated());
    }
}
