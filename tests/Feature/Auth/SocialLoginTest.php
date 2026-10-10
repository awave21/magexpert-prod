<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Sender\Contracts\SenderClient;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    config(['services.yandex.client_id' => 'test-id', 'services.vkid.client_id' => 'test-id']);

    $this->sent = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            $this->calls[] = compact('template', 'to');

            return ['id' => 'x', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(SenderClient::class, $this->sent);
});

function fakeSocialUser(string $provider, array $raw, ?string $email): void
{
    $user = (new SocialiteUser)->setRaw($raw)->map([
        'id' => $raw['id'],
        'name' => trim(($raw['first_name'] ?? '').' '.($raw['last_name'] ?? '')),
        'email' => $email,
    ]);

    $driver = Mockery::mock();
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

it('logs in a user who already linked yandex', function (): void {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '111']);
    fakeSocialUser('yandex', ['id' => '111', 'first_name' => 'Анна'], 'other@yandex.ru');

    $this->get('/auth/yandex/callback?code=x&state=y')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('links yandex to an existing account with the same email', function (): void {
    $user = User::factory()->create(['email' => 'anna@yandex.ru']);
    fakeSocialUser('yandex', ['id' => '222', 'first_name' => 'Анна'], 'Anna@Yandex.ru');

    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->where('provider', 'yandex')->value('provider_user_id'))->toBe('222');
});

it('creates a new account after the consents screen', function (): void {
    fakeSocialUser('yandex', ['id' => '333', 'first_name' => 'Пётр', 'last_name' => 'Сидоров'], 'petr@yandex.ru');

    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('social.complete'));
    $this->assertGuest();

    $this->get('/auth/complete')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Auth/SocialComplete')
        ->where('provider', 'Яндекс')
        ->where('firstName', 'Пётр')
        ->where('email', 'petr@yandex.ru'));

    $this->post('/auth/complete', ['first_name' => 'Пётр', 'last_name' => 'Сидоров'])
        ->assertSessionHasErrors(['privacy_consent', 'oferta_consent']);

    $this->post('/auth/complete', ['first_name' => 'Пётр', 'last_name' => 'Сидоров', 'email' => 'hacker@evil.ru', 'privacy_consent' => true, 'oferta_consent' => true])
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'petr@yandex.ru')->firstOrFail();
    $this->assertAuthenticatedAs($user);

    expect($user->email_verified_at)->not->toBeNull()
        ->and((bool) $user->privacy_consent)->toBeTrue()
        ->and($user->socialAccounts()->value('provider'))->toBe('yandex')
        ->and(User::query()->where('email', 'hacker@evil.ru')->exists())->toBeFalse()
        ->and($this->sent->calls[0]['template'])->toBe('welcome');
});

it('asks for an email when vk does not share it', function (): void {
    User::factory()->create(['email' => 'taken@mail.ru']);
    fakeSocialUser('vkid', ['id' => '444', 'first_name' => 'Ольга', 'last_name' => 'Смирнова'], null);

    $this->get('/auth/vkid/callback?code=x&device_id=d')->assertRedirect(route('social.complete'));

    $this->get('/auth/complete')->assertInertia(fn ($page) => $page->where('provider', 'ВКонтакте')->where('email', ''));

    $consents = ['first_name' => 'Ольга', 'last_name' => 'Смирнова', 'privacy_consent' => true, 'oferta_consent' => true];

    $this->post('/auth/complete', $consents)->assertSessionHasErrors('email');
    $this->post('/auth/complete', [...$consents, 'email' => 'taken@mail.ru'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/auth/complete', [...$consents, 'email' => 'Olga@Mail.ru'])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'olga@mail.ru')->firstOrFail();
    expect($user->email_verified_at)->toBeNull()
        ->and($user->socialAccounts()->value('provider'))->toBe('vkid');
});

it('returns to login when the user cancels or the provider is not configured', function (): void {
    $this->get('/auth/yandex/callback?error=access_denied')->assertRedirect(route('login'))->assertSessionHasErrors('email');

    config(['services.vkid.client_id' => null]);
    $this->get('/auth/vkid/redirect')->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->get('/auth/complete')->assertRedirect(route('login'));
    $this->get('/auth/google/redirect')->assertNotFound();
});

it('removes social links when the account is deleted', function (): void {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'vkid', 'provider_user_id' => '555']);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    expect(SocialAccount::query()->count())->toBe(0);
});
