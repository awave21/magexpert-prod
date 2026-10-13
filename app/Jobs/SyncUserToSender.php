<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\User;
use App\Sender\Client\HttpContactSyncClient;
use App\Sender\Client\SenderApiException;
use App\Sender\Contracts\ContactSyncClient;
use App\Sender\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Передаёт пользователя сайта в базу «Пользователи MagExpert» в Sender через открытый API.
 *
 * Данные пользователя читаются в момент выполнения, поэтому несколько изменений подряд схлопываются в одну задачу
 * (уникальность по пользователю на 30 секунд, пока задача ждёт в очереди). Если пользователя уже нет — контакт удаляется.
 * Смена адреса: старый адрес удаляется из базы, новый добавляется.
 */
class SyncUserToSender implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const LIST_DESCRIPTION = 'Все пользователи сайта mag-expert.ru. Обновляется сайтом автоматически; подписаны те, кто включил «Письма о мероприятиях».';

    /** Адреса обезличенных («удалённых») аккаунтов, их в базе быть не должно. */
    private const ANONYMIZED_DOMAIN = '@mag-expert.invalid';

    public int $tries = 5;

    public int $uniqueFor = 30;

    /**
     * @param  string|null  $email  адрес на момент постановки задачи: по нему удаляется контакт, если пользователя уже нет
     * @param  string|null  $previousEmail  прежний адрес, если адрес сменился: его контакт удаляется из базы
     * @param  list<string>  $tags  теги, которые нужно добавить контакту (например, event-63): к уже стоящим они добавляются
     */
    public function __construct(
        public int $userId,
        public ?string $email = null,
        public ?string $previousEmail = null,
        public array $tags = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return $this->userId.($this->previousEmail !== null ? ':'.mb_strtolower($this->previousEmail) : '').($this->tags !== [] ? ':'.implode(',', $this->tags) : '');
    }

    public function handle(ContactSyncClient $client): void
    {
        try {
            $this->sync($client, self::resolveListId($client));
        } catch (SenderApiException $exception) {
            // название занято базой, которую Sender собирает сам: повтор не поможет, нужно другое название
            if ($exception->status === 409) {
                $this->fail($exception);

                return;
            }

            if ($exception->status !== 404) {
                throw $exception;
            }

            // базу удалили или пересоздали в Sender: забываем ID и один раз пробуем с найденной заново
            self::forgetListId();

            try {
                $this->sync($client, self::resolveListId($client));
            } catch (SenderApiException $again) {
                if ($again->status !== 404 && $again->status !== 409) {
                    throw $again;
                }

                $this->fail($again);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Sender: не удалось передать пользователя в базу', ['user_id' => $this->userId, 'error' => $exception?->getMessage()]);
    }

    /**
     * Ошибки, которые повтором не исправить: неверные данные (400/422) пишутся в лог, и задача на этом заканчивается;
     * отказ по ключу (401/403) — задача падает в failed_jobs, её можно повторить после исправления ключа.
     * Обрыв соединения, 429 и 5xx — повтор по расписанию backoff; 404 — разбирается в handle().
     */
    private function sync(ContactSyncClient $client, int $listId): void
    {
        $user = User::query()->find($this->userId);
        $currentEmail = $user !== null ? mb_strtolower((string) $user->email) : null;

        try {
            if ($this->previousEmail !== null && mb_strtolower($this->previousEmail) !== $currentEmail) {
                $client->deleteContact($listId, $this->previousEmail);
            }

            if ($user === null) {
                if (filled($this->email)) {
                    $client->deleteContact($listId, $this->email);
                }

                return;
            }

            if (! self::syncable($user)) {
                return;
            }

            $payload = self::payload($user);
            $client->upsertContact($listId, $payload['email'], $payload['name'], $payload['data'], $payload['subscribed'], $this->tags);
        } catch (SenderApiException $exception) {
            if ($exception->retryable || $exception->status === 404) {
                throw $exception;
            }

            if ($exception->status === 422 || $exception->status === 400) {
                Log::warning('Sender: база не приняла данные пользователя', ['user_id' => $this->userId, 'error' => $exception->getMessage(), 'errors' => $exception->errors]);

                return;
            }

            $this->fail($exception);
        }
    }

    /**
     * Тег мероприятия в Sender: «<дата начала Y-m-d> <время H:i> <тема>», например
     * «2026-10-16 12:00 Медицина климактерия в клинических примерах». Без времени — дата и тема, без даты (по запросу) —
     * только тема. Если дату, время или тему поменяют, новые регистрации пойдут под новым тегом.
     */
    public static function eventTag(Event $event): string
    {
        return self::eventTagFrom($event->id, $event->title, $event->topic, $event->is_on_demand ? null : $event->start_date, $event->start_time);
    }

    /**
     * Теги мероприятия для контакта: автоматический «дата время тема» и теги, заданные вручную в форме мероприятия.
     *
     * @return list<string>
     */
    public static function eventTags(Event $event): array
    {
        return Contact::normalizeTags([self::eventTag($event), ...(array) $event->tags]);
    }

    /**
     * Тема — events.topic, иначе events.title (у мероприятий одного цикла название одинаковое, различается тема).
     * Символы вне допустимого набора тега заменяются пробелом, длина ограничена по границе слова.
     */
    public static function eventTagFrom(int $eventId, ?string $title, ?string $topic, mixed $startDate, mixed $startTime = null): string
    {
        $clean = fn (?string $text): string => trim((string) preg_replace('~\s+~u', ' ', (string) preg_replace('~[^\p{L}\p{N} _.:,()/+-]+~u', ' ', (string) $text)));

        $label = $clean($topic) !== '' ? $clean($topic) : $clean($title);
        $date = filled($startDate) ? Carbon::parse($startDate)->format('Y-m-d') : '';
        $time = $date !== '' && preg_match('~^(\d{1,2}):(\d{2})~', (string) $startTime, $match) ? sprintf('%02d:%s', $match[1], $match[2]) : '';
        $tag = implode(' ', array_filter([$date, $time, $label], fn (string $part): bool => $part !== ''));

        if (mb_strlen($tag) > Contact::TAG_MAX_LENGTH) {
            $cut = mb_substr($tag, 0, Contact::TAG_MAX_LENGTH);
            $space = mb_strrpos($cut, ' ');
            $tag = $space !== false && $space > 10 ? mb_substr($cut, 0, $space) : $cut;
        }

        $tag = trim((string) preg_replace('~^[^\p{L}\p{N}]+|[\s,.:\-/+]+$~u', '', $tag));

        return $tag !== '' ? $tag : 'event-'.$eventId;
    }

    /**
     * Передаёт в Sender тег мероприятия пользователя, которому выдан доступ (регистрация, оплата, выдача в админке).
     * Тег ставится независимо от согласия: без согласия контакт остаётся отписанным и рассылок не получает.
     * Письмо с подтверждением подписки не отправляется.
     */
    public static function dispatchEventTag(User $user, Event $event): void
    {
        if (! config('sender.client.users_sync') || ! HttpContactSyncClient::configured() || ! self::syncable($user)) {
            return;
        }

        self::dispatch($user->id, $user->email, null, self::eventTags($event));
    }

    /**
     * Попадает ли пользователь в базу: есть адрес и аккаунт не обезличен.
     */
    public static function syncable(User $user): bool
    {
        $email = mb_strtolower(trim((string) $user->email));

        return $email !== '' && ! str_ends_with($email, self::ANONYMIZED_DOMAIN);
    }

    /**
     * Контакт для Sender. Только то, что нужно для писем и сегментов: без телефона, пароля, токенов и даты рождения.
     *
     * @return array{email: string, name: string|null, data: array<string, string|null>, subscribed: bool}
     */
    public static function payload(User $user): array
    {
        $value = fn (?string $field): ?string => filled($field) ? trim($field) : null;

        return [
            'email' => mb_strtolower(trim((string) $user->email)),
            'name' => $value($user->full_name),
            'data' => [
                'first_name' => $value($user->first_name),
                'city' => $value($user->city),
                'specialization' => $value($user->specialization),
                'company' => $value($user->company),
                'position' => $value($user->position),
                'registered_at' => $user->created_at?->format('Y-m-d'),
                'source' => 'magexpert',
            ],
            'subscribed' => (bool) $user->newsletter_consent,
        ];
    }

    /**
     * Поля пользователя, изменение которых нужно передать в базу.
     *
     * @return list<string>
     */
    public static function trackedFields(): array
    {
        return ['email', 'first_name', 'last_name', 'middle_name', 'newsletter_consent', 'city', 'specialization', 'company', 'position'];
    }

    /**
     * ID базы: из SENDER_USERS_LIST_ID или найденный по названию (один раз, дальше из кеша).
     */
    public static function resolveListId(ContactSyncClient $client): int
    {
        $configured = (int) config('sender.client.users_list_id');

        if ($configured > 0) {
            return $configured;
        }

        $cached = (int) Cache::get(self::listCacheKey());

        if ($cached > 0) {
            return $cached;
        }

        $listId = $client->ensureList((string) config('sender.client.users_list_name'), self::LIST_DESCRIPTION);

        if ($listId > 0) {
            Cache::forever(self::listCacheKey(), $listId);
        }

        return $listId;
    }

    public static function forgetListId(): void
    {
        Cache::forget(self::listCacheKey());
    }

    /**
     * Ключ кеша зависит от адреса API и названия базы, чтобы смена любого из них не оставила чужой ID.
     */
    public static function listCacheKey(): string
    {
        return 'sender.users_list_id.'.md5(config('sender.client.url').'|'.config('sender.client.users_list_name'));
    }
}
