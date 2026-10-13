<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Bitrix24Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Передаёт в Bitrix24 изменения профиля: имя, город, специализацию, должность, email и телефон.
 *
 * Обновляется только уже существующий контакт: он создаётся при регистрации на мероприятие, а пользователи сайта,
 * ни разу не бывавшие в воронке, в CRM не попадают. Данные читаются в момент выполнения, поэтому несколько правок
 * подряд схлопываются в одну задачу. Пустые значения в CRM не затираются.
 */
class SyncUserToBitrix24 implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $uniqueFor = 30;

    /**
     * @param  string|null  $previousEmail  прежний адрес, если он сменился: по нему контакт находится в CRM
     */
    public function __construct(public int $userId, public ?string $previousEmail = null)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300, 1800];
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * Включена ли передача: в .env указан вебхук Bitrix24.
     */
    public static function enabled(): bool
    {
        return filled(config('bitrix24.webhook_url'));
    }

    /**
     * Поля профиля, изменение которых нужно передать в Bitrix24.
     *
     * @return list<string>
     */
    public static function trackedFields(): array
    {
        return ['first_name', 'last_name', 'middle_name', 'email', 'phone', 'city', 'specialization', 'position'];
    }

    public function handle(Bitrix24Service $bitrix): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || blank($user->email) || ! self::enabled()) {
            return;
        }

        $found = $bitrix->findContact($this->previousEmail ?: $user->email)
            ?? ($this->previousEmail ? $bitrix->findContact($user->email) : null);

        if ($found === null) {
            return;
        }

        $contactId = (int) $found['ID'];
        $fields = $this->fields($user, $bitrix->getContact($contactId) ?? []);

        if ($fields !== []) {
            $bitrix->updateContact($contactId, $fields);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel((string) config('bitrix24.log_channel', 'bitrix24'))->warning('Bitrix24: не удалось обновить контакт по профилю', [
            'user_id' => $this->userId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $current  контакт в CRM: нужны ID телефона и email, чтобы заменить значение, а не добавить второе
     * @return array<string, mixed>
     */
    private function fields(User $user, array $current): array
    {
        $uf = (array) config('bitrix24.uf_codes');
        $fields = array_filter([
            'NAME' => $user->first_name,
            'SECOND_NAME' => $user->middle_name,
            'LAST_NAME' => $user->last_name,
            ($uf['city'] ?? 'UF_CRM_ITS_DICT_CITY') => $user->city,
            ($uf['specialization'] ?? 'UF_CRM_1642077639') => $user->specialization,
            ($uf['post'] ?? 'UF_CRM_POST') => $user->position,
        ], fn ($value): bool => filled($value));

        $email = mb_strtolower(trim((string) $user->email));
        $emails = (array) ($current['EMAIL'] ?? []);

        if (! collect($emails)->contains(fn ($item): bool => mb_strtolower((string) ($item['VALUE'] ?? '')) === $email)) {
            $fields['EMAIL'] = [['ID' => $emails[0]['ID'] ?? null, 'VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
        }

        $phone = trim((string) $user->phone);
        $phones = (array) ($current['PHONE'] ?? []);

        if ($phone !== '' && ! collect($phones)->contains(fn ($item): bool => preg_replace('~\D~', '', (string) ($item['VALUE'] ?? '')) === preg_replace('~\D~', '', $phone))) {
            $fields['PHONE'] = [['ID' => $phones[0]['ID'] ?? null, 'VALUE' => $phone, 'VALUE_TYPE' => 'MOBILE']];
        }

        // пустой ID означает «добавить новое значение»
        foreach (['EMAIL', 'PHONE'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = array_map(fn (array $item): array => array_filter($item, fn ($v): bool => $v !== null), $fields[$key]);
            }
        }

        return $fields;
    }
}
