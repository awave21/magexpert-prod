<?php

namespace App\Services;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use RuntimeException;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Вход через VK ID (OAuth 2.1 с PKCE, id.vk.com).
 *
 * Тем же приложением VK ID входят через Одноклассники и Mail.ru: параметр provider на странице VK ID
 * сразу открывает нужный способ входа, а в ответ приходит один и тот же аккаунт VK ID.
 */
class VkIdProvider extends AbstractProvider
{
    public const IDENTIFIER = 'VKID';

    /**
     * Способы входа на странице VK ID: значение параметра provider.
     */
    public const VIA = ['vkid', 'ok_ru', 'mail_ru'];

    protected $scopes = ['email'];

    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://id.vk.com/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://id.vk.com/oauth2/auth';
    }

    /**
     * VK ID меняет код на токен без секрета приложения, но с device_id и state из адреса возврата.
     *
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'code' => $code,
            'code_verifier' => $this->request->session()->pull('code_verifier'),
            'device_id' => (string) $this->request->input('device_id'),
            'state' => (string) $this->request->input('state'),
            'redirect_uri' => $this->redirectUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $response = json_decode((string) $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => $this->getTokenHeaders($code),
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ])->getBody(), true);

        if (! is_array($response) || empty($response['access_token'])) {
            throw new RuntimeException('VK ID не выдал токен: '.(is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'пустой ответ') : 'пустой ответ'));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = json_decode((string) $this->getHttpClient()->post('https://id.vk.com/oauth2/user_info', [
            RequestOptions::FORM_PARAMS => ['client_id' => $this->clientId, 'access_token' => $token],
        ])->getBody(), true);

        if (! is_array($response) || ! is_array($response['user'] ?? null)) {
            throw new RuntimeException('VK ID не отдал профиль: '.(is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'пустой ответ') : 'пустой ответ'));
        }

        return $response['user'];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => (string) $user['user_id'],
            'name' => trim(Arr::get($user, 'first_name', '').' '.Arr::get($user, 'last_name', '')),
            'email' => Arr::get($user, 'email') ?: null,
            'avatar' => Arr::get($user, 'avatar'),
        ]);
    }
}
