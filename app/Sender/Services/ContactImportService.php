<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use Illuminate\Support\Str;

/**
 * Загрузка подписчиков в базу из CSV (в том числе сохранённого из Excel) или из списка адресов.
 *
 * Первая строка считается заголовком, если в ней нет адресов. Колонки email и имени узнаются
 * по названию, остальные колонки сохраняются как переменные подписчика. Перед загрузкой
 * можно посмотреть колонки (preview) и передать своё сопоставление (mapping).
 */
class ContactImportService
{
    private const EMAIL_HEADERS = ['email', 'e-mail', 'e_mail', 'mail', 'почта', 'емейл', 'имейл', 'электронная почта', 'эл. почта', 'адрес'];

    private const NAME_HEADERS = ['name', 'имя', 'фио', 'first_name', 'firstname', 'имя и фамилия', 'полное имя', 'full_name'];

    /**
     * Сколько строк с ошибками и повторов показать в отчёте: остальные только считаются.
     */
    private const REPORT_LIMIT = 100;

    /**
     * Сколько первых строк файла показать при просмотре.
     */
    private const SAMPLE_ROWS = 5;

    /**
     * Колонки файла до загрузки: что в них, примеры значений и догадка, что это за колонка.
     *
     * @return array{rows: int, header: bool, columns: list<array{index: int, header: string, samples: list<string>, role: 'email'|'name'|'field', key: string}>, sample_rows: list<list<string>>}
     */
    public function preview(string $content): array
    {
        $rows = $this->rows($this->toUtf8($content));

        if ($rows === []) {
            return ['rows' => 0, 'header' => false, 'columns' => [], 'sample_rows' => []];
        }

        $cells = array_column($rows, 1);
        [$emailColumn, $nameColumn, $extra, $hasHeader] = $this->columns($cells);
        $headers = $hasHeader ? array_shift($cells) : [];
        $width = max(array_map('count', $cells ?: [[]]) ?: [0]);
        $width = max($width, count($headers));
        $columns = [];

        for ($index = 0; $index < $width; $index++) {
            $samples = collect($cells)->map(fn (array $row): string => (string) ($row[$index] ?? ''))->filter(fn (string $v): bool => $v !== '')
                ->take(self::SAMPLE_ROWS)->map(fn (string $v): string => Str::limit($v, 60))->values()->all();
            $role = $index === $emailColumn ? 'email' : ($index === $nameColumn ? 'name' : 'field');
            $header = trim((string) ($headers[$index] ?? ''));

            $columns[] = [
                'index' => $index,
                'header' => $header,
                'samples' => $samples,
                'role' => $role,
                'key' => $extra[$index] ?? $this->key($header, $index),
            ];
        }

        $sampleRows = array_map(
            fn (array $row): array => array_map(fn (int $i): string => Str::limit((string) ($row[$i] ?? ''), 60), range(0, max(0, $width - 1))),
            array_slice($cells, 0, self::SAMPLE_ROWS),
        );

        return ['rows' => count($cells), 'header' => $hasHeader, 'columns' => $columns, 'sample_rows' => $sampleRows];
    }

    /**
     * added — новые адреса, updated — уже были в базе (обновлены имя и переменные),
     * duplicates — повторы внутри файла (сохраняется последняя строка), skipped — строки без корректного адреса.
     *
     * $mapping — выбор пользователя после просмотра: колонка адреса, колонка имени, какие колонки
     * сохранить переменными и под какими ключами, есть ли строка заголовка. Колонки вне mapping не загружаются.
     *
     * $consent — у адресов есть согласие на рассылку. Без него новые адреса ждут подтверждения подписки
     * (pending — сколько их): рассылки им не уходят, пока они не откроют ссылку из письма подтверждения.
     * Адреса, которые уже были в базе, своё состояние сохраняют.
     *
     * @param  array{email: int, name?: ?int, fields?: array<int|string, string>, header?: bool}|null  $mapping
     * @return array{rows: int, added: int, updated: int, duplicates: int, skipped: int, pending: int, columns: list<string>,
     *     skipped_rows: list<array{line: int, value: string}>, duplicate_rows: list<array{line: int, email: string, first_line: int}>}
     */
    public function import(ContactList $list, string $content, ?array $mapping = null, bool $consent = true): array
    {
        $rows = $this->rows($this->toUtf8($content));
        $result = ['rows' => 0, 'added' => 0, 'updated' => 0, 'duplicates' => 0, 'skipped' => 0, 'pending' => 0, 'columns' => [], 'skipped_rows' => [], 'duplicate_rows' => []];
        // базы из приложения повторяют согласие пользователей сайта: подтверждать там нечего
        $consent = $consent || $list->isSynced();

        if ($rows === []) {
            return $result;
        }

        [$emailColumn, $nameColumn, $extra, $hasHeader] = $mapping === null
            ? $this->columns(array_column($rows, 1))
            : $this->mapped($mapping);
        $result['columns'] = array_values($extra);

        if ($hasHeader) {
            array_shift($rows);
        }

        $result['rows'] = count($rows);

        /** @var array<string, array{email: string, name: ?string, data: array<string, string>}> $contacts */
        $contacts = [];
        /** @var array<string, int> $firstLine */
        $firstLine = [];

        foreach ($rows as [$line, $row]) {
            $email = Str::lower(trim((string) ($row[$emailColumn] ?? '')));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['skipped']++;
                if (count($result['skipped_rows']) < self::REPORT_LIMIT) {
                    $result['skipped_rows'][] = ['line' => $line, 'value' => Str::limit(implode('; ', array_filter($row, fn ($v) => $v !== '')), 120)];
                }

                continue;
            }

            if (isset($firstLine[$email])) {
                $result['duplicates']++;
                if (count($result['duplicate_rows']) < self::REPORT_LIMIT) {
                    $result['duplicate_rows'][] = ['line' => $line, 'email' => $email, 'first_line' => $firstLine[$email]];
                }
            } else {
                $firstLine[$email] = $line;
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
                    'confirmation_token' => $consent ? null : Str::random(48),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($insert !== []) {
                Contact::query()->insert($insert);
                $result['added'] += count($insert);
                $result['pending'] += $consent ? 0 : count($insert);
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
     * Строки файла с номером строки (с единицы), пустые строки пропускаются.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private function rows(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', rtrim($content)) ?: [];
        $first = collect($lines)->first(fn (string $line): bool => trim($line) !== '') ?? '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            // список адресов без разделителей: по одному на строке или через пробел
            if (substr_count($first, $delimiter) === 0) {
                foreach (preg_split('/\s+/', trim($line)) ?: [] as $email) {
                    $rows[] = [$index + 1, [$email]];
                }

                continue;
            }

            $rows[] = [$index + 1, array_map(fn ($v): string => trim((string) $v), str_getcsv($line, $delimiter, '"', ''))];
        }

        return $rows;
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
            $extra[$index] = $this->key($header, $index);
        }

        return [$emailColumn, $nameColumn, $extra, true];
    }

    /**
     * @param  array{email: int, name?: ?int, fields?: array<int|string, string>, header?: bool}  $mapping
     * @return array{0: int, 1: ?int, 2: array<int, string>, 3: bool}
     */
    private function mapped(array $mapping): array
    {
        $email = (int) $mapping['email'];
        $name = isset($mapping['name']) ? (int) $mapping['name'] : null;
        $extra = [];

        foreach ($mapping['fields'] ?? [] as $index => $key) {
            if ((int) $index !== $email && (int) $index !== $name) {
                $extra[(int) $index] = $key;
            }
        }

        return [$email, $name, $extra, (bool) ($mapping['header'] ?? false)];
    }

    /**
     * Ключ переменной из названия колонки: «Город» → gorod.
     */
    private function key(string $header, int $index): string
    {
        return Str::slug(Str::ascii($header), '_') ?: 'field_'.($index + 1);
    }
}
