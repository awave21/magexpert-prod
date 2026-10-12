<?php

use App\Services\VkIdProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;

function vkIdProvider(array $query = [], array $responses = [], array &$history = []): VkIdProvider
{
    $request = Request::create('/auth/vkid/callback', 'GET', $query);
    $request->setLaravelSession($session = app('session')->driver());
    $session->put(['state' => 'state-from-session-0123456789abcdef', 'code_verifier' => 'verifier-from-session']);

    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $provider = new VkIdProvider($request, 'app-54818666', '', 'https://mag-expert.test/auth/vkid/callback');
    $provider->setHttpClient(new Client(['handler' => $stack]));

    return $provider;
}

it('opens the vk id page with pkce and the chosen sign-in method', function (string $via): void {
    $location = vkIdProvider()->with(['provider' => $via])->redirect()->getTargetUrl();
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://id.vk.com/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'app-54818666',
            'redirect_uri' => 'https://mag-expert.test/auth/vkid/callback',
            'response_type' => 'code',
            'scope' => 'email',
            'code_challenge_method' => 'S256',
            'provider' => $via,
        ])
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32)
        ->and($query['code_challenge'])->not->toBeEmpty();
})->with(VkIdProvider::VIA);

it('exchanges the code with device_id and reads the profile', function (): void {
    $history = [];
    $provider = vkIdProvider(
        ['code' => 'vk-code', 'state' => 'state-from-session-0123456789abcdef', 'device_id' => 'device-1'],
        [
            new Response(200, [], json_encode(['access_token' => 'token-1', 'refresh_token' => 'r', 'expires_in' => 3600, 'user_id' => 701])),
            new Response(200, [], json_encode(['user' => ['user_id' => '701', 'first_name' => 'Анна', 'last_name' => 'Иванова', 'email' => 'anna@ok.ru', 'phone' => '79991234567']])),
        ],
        $history,
    );

    $user = $provider->user();

    parse_str((string) $history[0]['request']->getBody(), $tokenFields);
    parse_str((string) $history[1]['request']->getBody(), $infoFields);

    expect((string) $history[0]['request']->getUri())->toBe('https://id.vk.com/oauth2/auth')
        ->and($tokenFields)->toBe([
            'grant_type' => 'authorization_code',
            'client_id' => 'app-54818666',
            'code' => 'vk-code',
            'code_verifier' => 'verifier-from-session',
            'device_id' => 'device-1',
            'state' => 'state-from-session-0123456789abcdef',
            'redirect_uri' => 'https://mag-expert.test/auth/vkid/callback',
        ])
        ->and((string) $history[1]['request']->getUri())->toBe('https://id.vk.com/oauth2/user_info')
        ->and($infoFields)->toBe(['client_id' => 'app-54818666', 'access_token' => 'token-1'])
        ->and($user->getId())->toBe('701')
        ->and($user->getName())->toBe('Анна Иванова')
        ->and($user->getEmail())->toBe('anna@ok.ru')
        ->and($user->getRaw()['phone'])->toBe('79991234567');
});

it('fails when vk id does not issue a token', function (): void {
    $provider = vkIdProvider(
        ['code' => 'old-code', 'state' => 'state-from-session-0123456789abcdef', 'device_id' => 'd'],
        [new Response(200, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'Code is expired']))],
    );

    expect(fn () => $provider->user())->toThrow(RuntimeException::class, 'Code is expired');
});

it('rejects a callback with someone else\'s state', function (): void {
    $provider = vkIdProvider(['code' => 'c', 'state' => 'forged-state', 'device_id' => 'd']);

    expect(fn () => $provider->user())->toThrow(Laravel\Socialite\Two\InvalidStateException::class);
});
