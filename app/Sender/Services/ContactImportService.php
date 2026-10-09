<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use Illuminate\Support\Str;

/**
 * Загрузка подписчиков в базу из CSV (в том числе сохранённого из Excel) или из списка адресов.
 *
 * Первая строка считается заголовком, если в ней нет адресов. Колонки email и имени узнаются
 * по названию, остальные колонки сохраняются как переменные подписчика.
 */
class ContactImportService
{
    private const EMAIL_HEADERS = ['email', 'e-mail', 'e_mail', 'mail', 'почта', 'емейл', 'имейл', 'электронная почта', 'эл. почта', 'адрес'];

    private const NAME_HEADERS = ['name', 'имя', 'фио', 'first_name', 'firstname', 'имя и фамилия', 'полное имя', 'full_name'];

    /**
     * @return array{added: int, updated: int, skipped: int, columns: list<string>}
     */
    public function import(ContactList $list, string $content): array
    {
        $rows = $this->rows($this->toUtf8($content));
        $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'columns' => []];

        if ($rows === []) {
            return $result;
        }

        [$emailColumn, $nameColumn, $extra, $hasHeader] = $this->columns($rows);
        $result['columns'] = array_values($extra);

        if ($hasHeader) {
            array_shift($rows);
        }

        /** @var array<string, array{email: string, name: ?string, data: array<string, string>}> $contacts */
        $contacts = [];

        foreach ($rows as $row) {
            $email = Str::lower(trim((string) ($row[$emailColumn] ?? '')));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (implode('', $row) !== '') {
                    $result['skipped']++;
                }

                continue;
            }

            $data = [];
            foreach ($extra as $index => $key) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value !== '') {
                    $data[$key] = $value;
                }
            }

            $name = $nameColumn !== null ? trim((string) ($row[$nameColumn] ?? '')) : '';
            $contacts[$email] = ['email' => $email, 'name' => $name !== '' ? Str::limit($name, 250, '') : null, 'data' => $data];
        }

        foreach (array_chunk($contacts, 500, true) as $chunk) {
            $existing = $list->contacts()->whereIn('email', array_keys($chunk))->get()->keyBy('email');
            $now = now();
            $insert = [];

            foreach ($chunk as $email => $contact) {
                $current = $existing->get($email);

                if ($current instanceof Contact) {
                    $current->fill([
                        'name' => $contact['name'] ?? $current->name,
                        'data' => array_merge($current->data ?? [], $contact['data']),
                    ])->save();
                    $result['updated']++;

                    continue;
                }

                $insert[] = [
                    'organization_id' => $list->organization_id,
                    'list_id' => $list->id,
                    'email' => $email,
                    'name' => $contact['name'],
                    'data' => $contact['data'] === [] ? null : json_encode($contact['data'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($insert !== []) {
                Contact::query()->insert($insert);
                $result['added'] += count($insert);
            }
        }

        $list->touch();

        return $result;
    }

    /**
     * Excel в России сохраняет CSV в Windows-1251.
     */
    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return mb_check_encoding($content, 'UTF-8') ? $content : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();

        // список адресов без разделителей: по одному или через пробел
        if (substr_count($first, $delimiter) === 0) {
            return collect($lines)
                ->flatMap(fn (string $line): array => preg_split('/\s+/', trim($line)) ?: [])
                ->filter()
                ->map(fn (string $email): array => [$email])
                ->values()
                ->all();
        }

        return collect($lines)
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): array => array_map(fn ($v): string => trim((string) $v), str_getcsv($line, $delimiter, '"', '')))
            ->values()
            ->all();
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: int, 1: ?int, 2: array<int, string>, 3: bool}
     */
    private function columns(array $rows): array
    {
        $first = $rows[0];
        $hasHeader = collect($first)->every(fn (string $cell): bool => ! str_contains($cell, '@'));

        if (! $hasHeader) {
            $emailColumn = (int) collect($first)->search(fn (string $cell): bool => str_contains($cell, '@'));
            $nameColumn = count($first) > 1 ? ($emailColumn === 0 ? 1 : 0) : null;

            return [$emailColumn, $nameColumn, [], false];
        }

        $headers = array_map(fn (string $h): string => Str::lower(trim($h)), $first);
        $emailColumn = collect($headers)->search(fn (string $h): bool => in_array($h, self::EMAIL_HEADERS, true));
        $nameColumn = collect($headers)->search(fn (string $h): bool => in_array($h, self::NAME_HEADERS, true));

        if ($emailColumn === false) {
            $sample = $rows[1] ?? [];
            $emailColumn = collect($sample)->search(fn (string $cell): bool => str_contains($cell, '@'));
        }

        $emailColumn = $emailColumn === false ? 0 : (int) $emailColumn;
        $nameColumn = $nameColumn === false ? null : (int) $nameColumn;

        $extra = [];
        foreach ($headers as $index => $header) {
            if ($index === $emailColumn || $index === $nameColumn || $header === '') {
                continue;
            }
            $key = Str::slug(Str::ascii($header), '_') ?: 'field_'.($index + 1);
            $extra[$index] = $key;
        }

        return [$emailColumn, $nameColumn, $extra, true];
    }
}
