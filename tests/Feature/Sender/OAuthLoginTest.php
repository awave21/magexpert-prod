<?php

use App\Sender\Models\Organization;
use App\Sender\Models\SocialAccount;
use App\Sender\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    config(['services.yandex.client_id' => 'id', 'services.vkid.client_id' => 'id', 'sender.ui_url' => null]);
});

function senderSocial(string $provider, string $id, ?string $email, string $first = 'Анна', string $last = 'Иванова'): void
{
    $user = (new SocialiteUser)->setRaw(['id' => $id, 'first_name' => $first, 'last_name' => $last])
        ->map(['id' => $id, 'name' => "{$first} {$last}", 'email' => $email]);
    $driver = Mockery::mock();
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

function oauthCode(Illuminate\Testing\TestResponse $response, string $page): string
{
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain("/sender/{$page}?oauth=");
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['oauth'];
}

it('signs in an existing sender user by the email from yandex and links the account', function (): void {
    $organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $user = User::create(['organization_id' => $organization->id, 'name' => 'Админ', 'email' => 'anna@yandex.ru', 'password' => 'secret-pass']);
    senderSocial('yandex', 'y1', 'Anna@Yandex.ru');

    $code = oauthCode($this->get('/sender/oauth/yandex/callback?code=x&state=s'), 'login');

    $token = $this->postJson('/api/sender/v1/admin/oauth/exchange', ['code' => $code])
        ->assertOk()->assertJsonPath('user.email', 'anna@yandex.ru')->json('token');

    $this->withToken($token)->getJson('/api/sender/v1/admin/me')->assertOk()->assertJsonPath('data.email', 'anna@yandex.ru');

    // код одноразовый
    $this->postJson('/api/sender/v1/admin/oauth/exchange', ['code' => $code])->assertUnprocessable();
    expect(SocialAccount::query()->where('user_id', $user->id)->value('provider_user_id'))->toBe('y1');
});

it('registers a new organization after vk login without a password', function (): void {
    senderSocial('vkid', 'v1', null, 'Пётр', 'Сидоров');

    $code = oauthCode($this->get('/sender/oauth/vkid/callback?code=x&device_id=d'), 'register');

    $this->getJson('/api/sender/v1/admin/oauth/pending?code='.$code)
        ->assertOk()->assertJsonPath('data', ['provider' => 'ВКонтакте', 'name' => 'Пётр Сидоров', 'email' => '']);

    $this->postJson('/api/sender/v1/admin/oauth/register', ['code' => $code, 'organization' => 'Клиника'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email', 'accept_policy']);

    $this->postJson('/api/sender/v1/admin/oauth/register', ['code' => $code, 'organization' => 'Клиника', 'email' => 'Petr@Clinic.ru', 'accept_policy' => true])
        ->assertCreated()->assertJsonPath('user.email', 'petr@clinic.ru')->assertJsonPath('user.organization', 'Клиника');

    $user = User::query()->where('email', 'petr@clinic.ru')->firstOrFail();
    expect($user->name)->toBe('Пётр Сидоров')
        ->and($user->socialAccounts()->value('provider'))->toBe('vkid');

    // следующий вход через ВК сразу в аккаунт
    senderSocial('vkid', 'v1', null);
    oauthCode($this->get('/sender/oauth/vkid/callback?code=y&device_id=d'), 'login');

    $this->postJson('/api/sender/v1/admin/oauth/register', ['code' => $code, 'organization' => 'Ещё', 'email' => 'x@y.ru', 'accept_policy' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('returns to the login page with a message when cancelled or not configured', function (): void {
    $location = $this->get('/sender/oauth/yandex/callback?error=access_denied')->headers->get('Location');
    expect(urldecode((string) $location))->toContain('/sender/login?error=Вход через Яндекс отменён');

    config(['services.vkid.client_id' => null]);
    expect(urldecode((string) $this->get('/sender/oauth/vkid/redirect')->headers->get('Location')))->toContain('пока не настроен');

    $this->getJson('/api/sender/v1/admin/oauth/pending?code=nope')->assertNotFound();
});

it('serves oauth routes on the sender subdomain before the admin ui', function (): void {
    config(['sender.ui_url' => 'https://mail.mag-expert.test']);
    // маршруты читают адрес поддомена при загрузке, поэтому перечитываем их
    app('router')->setRoutes(new Illuminate\Routing\RouteCollection);
    require base_path('app/Sender/routes.php');
    senderSocial('yandex', 'y9', 'new@yandex.ru');

    $location = (string) $this->get('https://mail.mag-expert.test/oauth/yandex/callback?code=x')->headers->get('Location');

    expect($location)->toStartWith('https://mail.mag-expert.test/register?oauth=');
});
