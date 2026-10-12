<?php

namespace App\Sender\Services;

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendSubscriptionConfirmationsJob;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Добавление и обновление подписчиков базы из внешней системы (API): по одному и пакетами до 1000 адресов.
 *
 * subscribed=false — подписчик есть в базе, но отписан, писем не получает. subscribed=true — новый адрес сразу подписан,
 * а если база требует подтверждения подписки, ждёт его: ему уходит одно письмо со ссылкой (через
 * SendSubscriptionConfirmationsJob с её порциями и лимитами). Отписанный адрес подписывается снова; если подписку
 * он так и не подтвердил, снова ждёт подтверждения, но второе письмо ему не уходит никогда.
 * Блок-лист организации (отказы, жалобы, отписка) не трогается: такие адреса по-прежнему ничего не получают.
 * Имя и переменные объединяются с уже сохранёнными, как при загрузке файла; null в переменной удаляет её.
 */
class ContactUpsertService
{
    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    private const CHUNK = 500;

    /**
     * База по названию без учёта регистра и пробелов по краям; нет — создаётся.
     *
     * @return array{0: ContactList, 1: bool} база и была ли она создана
     */
    public function findOrCreateList(Organization $organization, string $name, ?string $description = null): array
    {
        $name = trim($name);

        if (($list = $this->findList($organization, $name)) !== null) {
            return [$list, false];
        }

        try {
            $list = $organization->lists()->create(['name' => $name, 'description' => $description]);

            return [$list->refresh(), true];
        } catch (UniqueConstraintViolationException $e) {
            // ту же базу только что создал параллельный запрос
            return [$this->findList($organization, $name) ?? throw $e, false];
        }
    }

    /**
     * @param  array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}  $row  проверенная строка
     * @return array{email: string, status: string, created: bool}
     */
    public function upsert(ContactList $list, array $row): array
    {
        $result = $this->apply($list, [$row]);

        return $result['contacts'][$row['email']];
    }

    /**
     * @param  list<array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}>  $rows  проверенные строки
     * @return array{created: int, updated: int, subscribed: int, pending: int, unsubscribed: int}
     */
    public function batch(ContactList $list, array $rows): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'subscribed' => 0, 'pending' => 0, 'unsubscribed' => 0];

        if ($rows === []) {
            return $totals;
        }

        foreach ($this->apply($list, $rows)['contacts'] as $contact) {
            $totals[$contact['created'] ? 'created' : 'updated']++;
            $totals[$contact['status']]++;
        }

        return $totals;
    }

    public static function status(Contact $contact): string
    {
        return match (true) {
            $contact->unsubscribed_at !== null => self::STATUS_UNSUBSCRIBED,
            $contact->isPending() => self::STATUS_PENDING,
            default => self::STATUS_SUBSCRIBED,
        };
    }

    private function findList(Organization $organization, string $name): ?ContactList
    {
        $needle = mb_strtolower(trim($name));

        // баз у организации немного: сравнение в PHP одинаково понимает кириллицу во всех СУБД
        return $organization->lists()->get()->first(fn (ContactList $list): bool => mb_strtolower(trim($list->name)) === $needle);
    }

    /**
     * Строки применяются по порядку: повтор адреса в пакете продолжает изменения предыдущей строки.
     *
     * @param  list<array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}>  $rows
     * @return array{contacts: array<string, array{email: string, status: string, created: bool}>}
     */
    private function apply(ContactList $list, array $rows): array
    {
        try {
            return $this->transaction($list, $rows);
        } catch (UniqueConstraintViolationException) {
            // адрес только что добавил параллельный запрос: второй проход его уже найдёт
            return $this->transaction($list, $rows);
        }
    }

    /**
     * @param  list<array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}>  $rows
     * @return array{contacts: array<string, array{email: string, status: string, created: bool}>}
     */
    private function transaction(ContactList $list, array $rows): array
    {
        $awaitingLetter = [];

        $contacts = Contact::query()->getConnection()->transaction(function () use ($list, $rows, &$awaitingLetter): array {
            /** @var array<string, list<array<string, mixed>>> $byEmail */
            $byEmail = [];
            foreach ($rows as $row) {
                $byEmail[$row['email']][] = $row;
            }

            $contacts = [];
            $now = now();

            foreach (array_chunk($byEmail, self::CHUNK, true) as $chunk) {
                /** @var Collection<string, Contact> $existing */
                $existing = $list->contacts()->whereIn('email', array_keys($chunk))->get()->keyBy('email');
                $insert = [];

                foreach ($chunk as $email => $emailRows) {
                    $current = $existing->get($email);
                    $created = $current === null;
                    $contact = $current ?? new Contact(['organization_id' => $list->organization_id, 'list_id' => $list->id, 'email' => $email]);
                    $isNew = $created;

                    foreach ($emailRows as $row) {
                        if ($this->applyRow($list, $contact, $row, $isNew)) {
                            $awaitingLetter[$email] = true;
                        }
                        $isNew = false;
                    }

                    if ($created) {
                        $insert[] = [
                            'organization_id' => $list->organization_id,
                            'list_id' => $list->id,
                            'email' => $email,
                            'name' => $contact->name,
                            'data' => empty($contact->data) ? null : json_encode($contact->data, JSON_UNESCAPED_UNICODE),
                            'unsubscribed_at' => $contact->unsubscribed_at,
                            'confirmation_token' => $contact->confirmation_token,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif ($contact->isDirty()) {
                        $contact->save();
                    }

                    $contacts[$email] = ['email' => $email, 'status' => self::status($contact), 'created' => $created];
                }

                if ($insert !== []) {
                    Contact::query()->insert($insert);
                }
            }

            $list->touch();

            return $contacts;
        });

        $created = array_filter($contacts, fn (array $contact): bool => $contact['created']);

        if ($created !== []) {
            CheckContactsJob::dispatch($list->id)->onQueue(config('sender.queue'));
        }

        if ($awaitingLetter !== [] && $this->hasUnsuppressed($list, array_keys($awaitingLetter))) {
            // письмо подтверждения уходит чуть позже: проверка успеет отсеять несуществующие адреса
            SendSubscriptionConfirmationsJob::dispatch($list->id)->onQueue(config('sender.queue'))->delay(now()->addMinutes(2));
        }

        return ['contacts' => $contacts];
    }

    /**
     * Применяет строку к подписчику в памяти.
     *
     * @param  array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}  $row
     * @return bool нужно ли отправить подписчику письмо подтверждения подписки
     */
    private function applyRow(ContactList $list, Contact $contact, array $row, bool $isNew): bool
    {
        if (isset($row['name'])) {
            $contact->name = Str::limit($row['name'], 250, '');
        }

        if (is_array($row['data'] ?? null)) {
            $contact->data = $this->mergeData($contact->data ?? [], $row['data']) ?: null;
        }

        $requireConfirmation = (bool) $list->require_confirmation && ! $list->isSynced();

        if (! ($row['subscribed'] ?? true)) {
            if ($contact->unsubscribed_at === null) {
                $contact->unsubscribed_at = now();
            }

            return false;
        }

        if ($isNew) {
            if (! $requireConfirmation) {
                return false;
            }

            $contact->confirmation_token = Str::random(48);

            return true;
        }

        if ($contact->unsubscribed_at === null) {
            return false;
        }

        $contact->unsubscribed_at = null;

        if (! $requireConfirmation || $contact->confirmed_at !== null) {
            return false;
        }

        $contact->confirmation_token ??= Str::random(48);

        return $contact->confirmation_sent_at === null;
    }

    /**
     * @param  array<mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    private function mergeData(array $current, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = match (true) {
                    $value === true => '1',
                    $value === false => '0',
                    default => (string) $value,
                };
            }
        }

        return $current;
    }

    /**
     * Есть ли среди адресов хоть один не из блок-листа организации: остальным письмо всё равно не уйдёт.
     *
     * @param  list<string>  $emails
     */
    private function hasUnsuppressed(ContactList $list, array $emails): bool
    {
        foreach (array_chunk($emails, self::CHUNK) as $chunk) {
            $suppressed = Suppression::query()->where('organization_id', $list->organization_id)->whereIn('email', $chunk)->count();

            if ($suppressed < count($chunk)) {
                return true;
            }
        }

        return false;
    }
}
