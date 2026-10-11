<?php

namespace App\Sender\Services;

use App\Sender\Models\SocialAccount;
use App\Sender\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Вход в Sender через Яндекс ID и VK ID.
 *
 * После возврата от провайдера интерфейс получает одноразовый код, а не токен: так токен не попадает
 * в адресную строку и историю браузера. Код меняется на токен (вход) или на данные для регистрации организации.
 */
class SocialLoginService
{
    private const CODE_TTL_SECONDS = 600;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * @param  array{provider: string, id: string, email: string, name: string}  $profile
     * @return array{type: 'login'|'register', code: string}
     */
    public function handle(array $profile): array
    {
        $user = SocialAccount::query()->where('provider', $profile['provider'])->where('provider_user_id', $profile['id'])->first()?->user;

        if ($user === null && $profile['email'] !== '') {
            $user = User::query()->where('email', $profile['email'])->first();
            $user?->socialAccounts()->updateOrCreate(['provider' => $profile['provider']], ['provider_user_id' => $profile['id']]);
        }

        if ($user !== null && $user->is_active) {
            return ['type' => 'login', 'code' => $this->remember(['type' => 'login', 'user_id' => $user->id], 60)];
        }

        return ['type' => 'register', 'code' => $this->remember(['type' => 'register', ...$profile])];
    }

    /**
     * @return array{token: string, user: User}|null
     */
    public function exchange(string $code): ?array
    {
        $data = Cache::pull($this->key($code));

        if (! is_array($data) || $data['type'] !== 'login') {
            return null;
        }

        $user = User::query()->with('organization')->find($data['user_id']);

        return $user === null ? null : ['token' => $this->auth->issueToken($user), 'user' => $user];
    }

    /**
     * @return array{provider: string, id: string, email: string, name: string}|null
     */
    public function pending(string $code): ?array
    {
        $data = Cache::get($this->key($code));

        return is_array($data) && $data['type'] === 'register' ? $data : null;
    }

    /**
     * @return array{token: string, user: User}
     */
    public function register(string $code, string $organization, string $email): array
    {
        $pending = $this->pending($code);
        Cache::forget($this->key($code));

        $result = $this->auth->register($organization, $pending['name'] ?: Str::before($email, '@'), $pending['email'] ?: $email, Str::random(40));
        $result['user']->socialAccounts()->create(['provider' => $pending['provider'], 'provider_user_id' => $pending['id']]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function remember(array $data, int $ttl = self::CODE_TTL_SECONDS): string
    {
        $code = Str::random(40);
        Cache::put($this->key($code), $data, $ttl);

        return $code;
    }

    private function key(string $code): string
    {
        return 'sender.oauth.'.hash('sha256', $code);
    }
}
