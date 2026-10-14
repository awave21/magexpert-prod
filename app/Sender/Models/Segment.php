<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сегмент: сохранённые условия отбора внутри одной базы. Состав не хранится, а считается на момент запроса,
 * поэтому сегмент обновляется вместе с базой. В рассылке выбирается наравне с базой.
 *
 * Условия складываются (И). Каждое — одно из:
 * - activity: {op: opened|not_opened, days} — открывали (не открывали) письма за последние days дней;
 * - field: {field, op: in|not_in, values} — дополнительное поле равно (не равно) одному из значений;
 * - tag: {op: any|none, tags} — есть хотя бы один из тегов (нет ни одного).
 */
class Segment extends SenderModel
{
    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_FIELD = 'field';

    public const TYPE_TAG = 'tag';

    public const OPS = [
        self::TYPE_ACTIVITY => ['opened', 'not_opened'],
        self::TYPE_FIELD => ['in', 'not_in'],
        self::TYPE_TAG => ['any', 'none'],
    ];

    public const MAX_CONDITIONS = 20;

    protected $table = 'sender_segments';

    protected $fillable = ['organization_id', 'list_id', 'name', 'description', 'conditions'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'segment_id');
    }

    /**
     * Правила проверки условий: для сохранения сегмента и для подсчёта ещё не сохранённого.
     *
     * @return array<string, mixed>
     */
    public static function conditionRules(string $prefix = 'conditions'): array
    {
        return [
            $prefix => ['array', 'max:'.self::MAX_CONDITIONS],
            $prefix.'.*.type' => ['required', 'in:'.implode(',', array_keys(self::OPS))],
            $prefix.'.*.op' => ['required', 'string'],
            $prefix.'.*.days' => ['required_if:'.$prefix.'.*.type,'.self::TYPE_ACTIVITY, 'nullable', 'integer', 'min:1', 'max:3650'],
            $prefix.'.*.field' => ['required_if:'.$prefix.'.*.type,'.self::TYPE_FIELD, 'nullable', 'regex:/^[a-z0-9_]{1,64}$/'],
            $prefix.'.*.values' => ['required_if:'.$prefix.'.*.type,'.self::TYPE_FIELD, 'nullable', 'array', 'max:200'],
            $prefix.'.*.values.*' => ['string', 'max:200'],
            $prefix.'.*.tags' => ['required_if:'.$prefix.'.*.type,'.self::TYPE_TAG, 'nullable', 'array', 'max:50'],
            $prefix.'.*.tags.*' => ['string', 'regex:'.Contact::TAG_PATTERN],
        ];
    }

    /**
     * Проверки, которые не выражаются правилами: оператор подходит к типу, значения не пустые,
     * каждое условие встречается один раз (активность и теги — по одному, поле — по одному на поле).
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return array<string, string> ключ ошибки => текст
     */
    public static function conditionErrors(array $conditions, string $prefix = 'conditions'): array
    {
        $errors = [];
        $seen = [];

        foreach (array_values($conditions) as $i => $condition) {
            $type = (string) ($condition['type'] ?? '');
            $key = $type === self::TYPE_FIELD ? 'field:'.($condition['field'] ?? '') : $type;

            if (! in_array($condition['op'] ?? null, self::OPS[$type] ?? [], true)) {
                $errors["{$prefix}.{$i}.op"] = 'Такого условия нет';
            }

            if ($type === self::TYPE_FIELD && empty($condition['values'])) {
                $errors["{$prefix}.{$i}.values"] = 'Выберите хотя бы одно значение';
            }

            if ($type === self::TYPE_TAG && empty($condition['tags'])) {
                $errors["{$prefix}.{$i}.tags"] = 'Выберите хотя бы один тег';
            }

            if (isset($seen[$key])) {
                $errors["{$prefix}.{$i}.type"] = 'Такое условие уже есть';
            }

            $seen[$key] = true;
        }

        return $errors;
    }

    /**
     * Только известные ключи каждого условия: в базу не попадает лишнее.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return list<array<string, mixed>>
     */
    public static function cleanConditions(array $conditions): array
    {
        return array_values(array_map(function (array $condition): array {
            return match ($condition['type']) {
                self::TYPE_ACTIVITY => ['type' => self::TYPE_ACTIVITY, 'op' => $condition['op'], 'days' => (int) $condition['days']],
                self::TYPE_FIELD => ['type' => self::TYPE_FIELD, 'op' => $condition['op'], 'field' => $condition['field'], 'values' => array_values(array_unique(array_map('strval', $condition['values'])))],
                default => ['type' => self::TYPE_TAG, 'op' => $condition['op'], 'tags' => Contact::normalizeTags($condition['tags'])],
            };
        }, $conditions));
    }
}
