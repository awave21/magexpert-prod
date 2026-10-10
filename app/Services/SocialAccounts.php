<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;

/**
 * Привязка Яндекс ID и VK ID к аккаунту сайта и подтверждение телефона номером оттуда:
 * у Яндекса и ВКонтакте номер уже проверен кодом, поэтому повторно его не проверяем.
 */
class SocialAccounts
{
    public const NAMES = ['yandex' => 'Яндекс', 'vkid' => 'ВКонтакте'];

    public const PHONE_FILLED = 'filled';

    public const PHONE_VERIFIED = 'verified';

    public const PHONE_MISMATCH = 'mismatch';

    public const PHONE_NONE = 'none';

    /**
     * @param  array{provider: string, id: string, email?: string, phone?: string}  $profile
     * @return bool false — этот аккаунт Яндекса или ВКонтакте уже привязан к другому пользователю
     */
    public function link(User $user, array $profile): bool
    {
        $owner = SocialAccount::query()->where('provider', $profile['provider'])->where('provider_user_id', $profile['id'])->value('user_id');

        if ($owner !== null && $owner !== $user->id) {
            return false;
        }

        $user->socialAccounts()->updateOrCreate(
            ['provider' => $profile['provider']],
            ['provider_user_id' => $profile['id'], 'email' => ($profile['email'] ?? '') ?: null],
        );

        return true;
    }

    /**
     * Номер из профиля Яндекса или ВКонтакте: заполняет пустой телефон или подтверждает совпадающий.
     * Другой номер не перезаписывает — человек мог указать рабочий телефон.
     */
    public function applyPhone(User $user, ?string $phone): string
    {
        $phone = $this->normalize($phone);

        if ($phone === null) {
            return self::PHONE_NONE;
        }

        $current = $this->normalize($user->phone);

        if ($current !== null && $current !== $phone) {
            return self::PHONE_MISMATCH;
        }

        $user->forceFill([
            'phone' => $current === null ? $this->format($phone) : $user->phone,
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ])->save();

        return $current === null ? self::PHONE_FILLED : self::PHONE_VERIFIED;
    }

    /**
     * Телефон из ответа провайдера: у Яндекса default_phone.number, у ВКонтакте phone.
     *
     * @param  array<string, mixed>  $raw
     */
    public function phoneFrom(string $provider, array $raw): ?string
    {
        $phone = $provider === 'yandex' ? ($raw['default_phone']['number'] ?? null) : ($raw['phone'] ?? null);

        return is_scalar($phone) ? $this->normalize((string) $phone) : null;
    }

    public function message(string $provider, string $phoneStatus): string
    {
        $name = self::NAMES[$provider];

        return match ($phoneStatus) {
            self::PHONE_FILLED => "{$name} привязан, телефон из профиля добавлен и подтверждён.",
            self::PHONE_VERIFIED => "{$name} привязан, телефон подтверждён.",
            self::PHONE_MISMATCH => "{$name} привязан. Телефон не подтверждён: в {$name} указан другой номер. Измените номер в профиле или в {$name}.",
            default => "{$name} привязан. {$name} не передал телефон: разрешите доступ к номеру при входе, чтобы подтвердить его.",
        };
    }

    /**
     * Только цифры, российские номера приводим к виду 7XXXXXXXXXX.
     */
    public function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7'.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    private function format(string $digits): string
    {
        return strlen($digits) === 11 && $digits[0] === '7'
            ? sprintf('+7 (%s) %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2))
            : '+'.$digits;
    }
}
