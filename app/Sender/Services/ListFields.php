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
