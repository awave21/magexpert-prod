<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;

/**
 * Дополнительные поля подписчиков базы и их частые значения: из них собирается условие отбора рассылки.
 */
class ListFields
{
    /**
     * Сколько частых значений показывать у поля.
     */
    public const VALUES = 30;

    /**
     * Теги подписчиков базы и сколько подписчиков с каждым, самые частые первыми.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tags(ContactList $list, int $limit = 200): array
    {
        $rows = Contact::query()->getConnection()->select(
            'select t.tag, count(*) as total from sender_contacts c, jsonb_array_elements_text(c.tags) as t(tag) '
            .'where c.list_id = ? and c.tags is not null group by t.tag order by total desc, t.tag limit '.(int) $limit,
            [$list->id],
        );

        return array_map(fn (object $row): array => ['tag' => (string) $row->tag, 'count' => (int) $row->total], $rows);
    }

    /**
     * @return list<array{key: string, values: list<array{value: string, count: int}>}>
     */
    public function of(ContactList $list): array
    {
        // поля считаются порциями в PHP: data бывает объектом или пустым массивом [], а значения — любыми строками
        $counts = [];
        $list->contacts()->whereNotNull('data')->select(['id', 'data'])->lazyById(1000)->each(function (Contact $contact) use (&$counts): void {
            foreach ((array) $contact->data as $key => $value) {
                if (is_string($key) && is_scalar($value) && (string) $value !== '') {
                    $counts[$key][(string) $value] = ($counts[$key][(string) $value] ?? 0) + 1;
                }
            }
        });

        return collect($counts)->map(function (array $values, int|string $key): array {
            arsort($values);

            return [
                'key' => (string) $key,
                'values' => collect($values)->take(self::VALUES)->map(fn (int $total, int|string $value): array => ['value' => (string) $value, 'count' => $total])->values()->all(),
            ];
        })->sortByDesc(fn (array $field): int => $field['values'][0]['count'] ?? 0)->values()->all();
    }
}
