<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Sender\SenderClient;
use App\Services\SocialAccounts;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    config(['services.yandex.client_id' => 'test-id', 'services.vkid.client_id' => 'test-id']);

    $this->sent = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null): array
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
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('with')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect()->away('https://oauth.example.test/authorize'));
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

it('does not sign in or link yandex to an account whose email is not confirmed', function (): void {
    $squatter = User::factory()->unverified()->create(['email' => 'anna@yandex.ru']);
    fakeSocialUser('yandex', ['id' => 'y-victim', 'first_name' => 'Анна'], 'anna@yandex.ru');

    $this->get('/auth/yandex/callback?code=x')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => SocialAccounts::UNVERIFIED_EMAIL]);

    $this->assertGuest();
    expect($squatter->socialAccounts()->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1)
        ->and(session()->has(App\Http\Controllers\Auth\SocialAuthController::PENDING))->toBeFalse();
});

it('finds an old account with a mixed-case email when signing in with yandex', function (): void {
    $user = User::factory()->create(['email' => 'Ivanov@Mail.ru']);
    fakeSocialUser('yandex', ['id' => '333', 'first_name' => 'Иван'], 'ivanov@mail.ru');

    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('does not open an account by an email from vk: the person signs in with a password first', function (): void {
    $victim = User::factory()->create(['email' => 'anna@clinic.ru']);
    fakeSocialUser('vkid', ['id' => 'v-attacker', 'first_name' => 'Чужой', 'phone' => '+79990001122'], 'anna@clinic.ru');

    $this->get('/auth/vkid/callback?code=x&device_id=d')->assertRedirect(route('social.complete'));
    $this->assertGuest();

    $this->post(route('social.store'), ['first_name' => 'Чужой', 'last_name' => 'Человек', 'privacy_consent' => true, 'oferta_consent' => true])
        ->assertSessionHasErrors('email');

    expect($victim->fresh()->socialAccounts()->count())->toBe(0)
        ->and($victim->fresh()->phone_verified_at)->toBeNull()
        ->and(User::query()->count())->toBe(1);
});

it('does not replace a yandex profile already linked to the account found by email', function (): void {
    $user = User::factory()->create(['email' => 'anna@yandex.ru']);
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => 'own']);
    fakeSocialUser('yandex', ['id' => 'other', 'first_name' => 'Анна'], 'anna@yandex.ru');

    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('social.complete'));

    $this->assertGuest();
    expect($user->socialAccounts()->value('provider_user_id'))->toBe('own');
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

it('opens odnoklassniki or mail.ru right away through vk id and ignores unknown methods', function (): void {
    config(['services.vkid.client_id' => 'app-id', 'services.vkid.phone' => false]);

    $location = $this->get('/auth/vkid/redirect?via=ok_ru')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://id.vk.com/authorize?')
        ->and($query['provider'])->toBe('ok_ru')
        ->and($query['redirect_uri'])->toBe(route('social.callback', 'vkid'));

    // в бою каждый запрос получает новый драйвер, а в тесте Socialite хранит его между запросами
    Socialite::forgetDrivers();
    $location = $this->get('/auth/vkid/redirect?via=google')->headers->get('Location');
    expect($location)->toStartWith('https://id.vk.com/authorize?')->not->toContain('provider=');

    expect($this->get('/auth/yandex/redirect?via=mail_ru')->headers->get('Location'))
        ->toStartWith('https://oauth.yandex.ru/authorize?')->not->toContain('provider=');
});

it('removes social links when the account is deleted', function (): void {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'vkid', 'provider_user_id' => '555']);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('takes a verified phone from yandex for a new account', function (): void {
    fakeSocialUser('yandex', ['id' => '601', 'first_name' => 'Анна', 'last_name' => 'Иванова', 'default_phone' => ['id' => 1, 'number' => '+79991234567']], 'anna@yandex.ru');

    $this->get('/auth/yandex/callback?code=x');
    $this->post('/auth/complete', ['first_name' => 'Анна', 'last_name' => 'Иванова', 'privacy_consent' => true, 'oferta_consent' => true]);

    $user = User::query()->where('email', 'anna@yandex.ru')->firstOrFail();
    expect($user->phone)->toBe('+7 (999) 123-45-67')
        ->and($user->phone_verified_at)->not->toBeNull();
});

it('links vk from the profile and confirms the matching phone', function (): void {
    $user = User::factory()->create(['phone' => '8 999 123-45-67']);
    fakeSocialUser('vkid', ['id' => '701', 'first_name' => 'Анна', 'phone' => '79991234567'], 'anna-vk@mail.ru');

    $this->actingAs($user)->from(route('dashboard'))->get('/auth/vkid/link')->assertRedirect('https://oauth.example.test/authorize');
    $this->get('/auth/vkid/callback?code=x&device_id=d')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('message', 'ВКонтакте привязан, телефон подтверждён.');

    expect($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and($user->fresh()->phone)->toBe('8 999 123-45-67')
        ->and($user->socialAccounts()->value('provider_user_id'))->toBe('701');
});

it('does not complain about the phone when it is already confirmed and vk does not share a number', function (?string $vkPhone): void {
    $user = User::factory()->create(['phone' => '+7 (917) 385-37-42', 'phone_verified_at' => now()->subDay()]);
    fakeSocialUser('vkid', array_filter(['id' => '901', 'first_name' => 'Максим', 'phone' => $vkPhone]), null);

    $this->actingAs($user)->from(route('cabinet.security'))->get('/auth/vkid/link');
    $this->get('/auth/vkid/callback?code=x&device_id=d')
        ->assertRedirect(route('cabinet.security'))
        ->assertSessionHas('message', 'ВКонтакте привязан.')
        ->assertSessionMissing('phone_result')
        ->assertSessionMissing('error');

    expect($user->fresh()->phone)->toBe('+7 (917) 385-37-42')
        ->and($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->value('provider'))->toBe('vkid');
})->with(['no number from vk' => null, 'another number in vk' => '79990001122']);

it('does not overwrite a different phone and refuses an account linked to someone else', function (): void {
    $user = User::factory()->create(['phone' => '+7 900 000-00-00']);
    fakeSocialUser('yandex', ['id' => '801', 'default_phone' => ['number' => '+79991234567']], 'x@yandex.ru');

    $this->actingAs($user)->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?code=x')->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'указан другой номер'));
    expect($user->fresh()->phone_verified_at)->toBeNull();

    $other = User::factory()->create();
    $other->socialAccounts()->create(['provider' => 'vkid', 'provider_user_id' => '802']);
    fakeSocialUser('vkid', ['id' => '802'], null);

    $this->get('/auth/vkid/link');
    $this->get('/auth/vkid/callback?code=x')->assertSessionHas('phone_result', fn (array $result): bool => $result['move'] === true);
    expect($user->socialAccounts()->where('provider', 'vkid')->exists())->toBeFalse();
});

it('links the social account after signing in with a password from the consents screen', function (): void {
    $user = User::factory()->create(['email' => 'work@clinic.ru']);
    fakeSocialUser('yandex', ['id' => '901', 'first_name' => 'Анна'], 'personal@yandex.ru');

    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('social.complete'));
    $this->get('/login')->assertInertia(fn ($page) => $page->where('linkProvider', 'Яндекс'));

    $this->post('/login', ['email' => 'work@clinic.ru', 'password' => 'password'])->assertSessionHas('message', fn (string $m): bool => str_starts_with($m, 'Яндекс привязан'));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->value('provider_user_id'))->toBe('901')
        ->and(User::query()->where('email', 'personal@yandex.ru')->exists())->toBeFalse();
});

it('unlinks a provider and resets phone verification when the number changes', function (): void {
    $user = User::factory()->create(['phone' => '+7 999 123-45-67', 'phone_verified_at' => now()]);
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '1001']);

    $this->actingAs($user)->delete('/auth/yandex/link')->assertSessionHas('message');
    expect($user->socialAccounts()->count())->toBe(0);

    $this->get('/profile')->assertInertia(fn ($page) => $page->where('phoneVerified', true)->where('social.0.key', 'yandex')->where('social.0.linked', false));

    $this->patch('/profile', ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email, 'phone' => '+7 999 123-45-67']);
    expect($user->fresh()->phone_verified_at)->not->toBeNull();

    $this->patch('/profile', ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email, 'phone' => '+7 900 111-22-33']);
    expect($user->fresh()->phone_verified_at)->toBeNull();
});

test('кнопки входа показываются только для провайдеров с ключами', function () {
    config(['services.yandex.client_id' => 'yandex-id', 'services.vkid.client_id' => null]);

    $this->get('/login')->assertInertia(fn ($page) => $page->where('socialProviders', ['yandex']));
    $this->getJson('/api/sender/v1/admin/oauth/providers')->assertOk()->assertExactJson(['data' => ['yandex'], 'registration' => false]);
});

it('returns to the security page when the link was opened without a known origin', function (): void {
    $user = User::factory()->create();
    fakeSocialUser('yandex', ['id' => '811'], 'nophone@yandex.ru');

    $this->actingAs($user)->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?code=x')
        ->assertRedirect(route('cabinet.security'))
        ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'не передал номер'));
});

it('returns to the page with an error when the user cancels at the provider', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('dashboard'))->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?error=access_denied')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'Привязка Яндекс отменена.');
});

it('offers phone confirmation only when the provider shares the number', function (): void {
    config(['services.yandex.client_id' => 'id', 'services.yandex.phone' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('phoneProviders', []));

    config(['services.yandex.phone' => true]);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('phoneProviders', ['yandex']));
});

it('shows why the phone was not confirmed right in the cabinet and asks yandex to confirm access again', function (): void {
    config(['services.yandex.client_id' => 'id', 'services.yandex.phone' => true]);
    $user = User::factory()->create(['phone' => '+7 900 000-00-00']);
    fakeSocialUser('yandex', ['id' => '821', 'default_phone' => ['number' => '+79991234567']], 'other@yandex.ru');

    $this->actingAs($user)->from(route('dashboard'))->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('dashboard'));

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('flash.phone_result.ok', false)
        ->where('flash.phone_result.text', fn (string $text): bool => str_contains($text, 'указан другой номер')));
});

it('confirms the phone when the same yandex account is linked again', function (): void {
    config(['services.yandex.client_id' => 'id', 'services.yandex.phone' => true]);
    $user = User::factory()->create(['phone' => '+7 999 123-45-67']);
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '831']);
    fakeSocialUser('yandex', ['id' => '831', 'default_phone' => ['number' => '+79991234567']], 'me@yandex.ru');

    $this->actingAs($user)->from(route('dashboard'))->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?code=x')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('message', 'Яндекс привязан, телефон подтверждён.');

    expect($user->fresh()->phone_verified_at)->not->toBeNull();
});

it('offers to move the yandex login here and confirms the phone in one click', function (): void {
    config(['services.yandex.client_id' => 'id', 'services.yandex.phone' => true]);
    $owner = User::factory()->create(['email' => 'moskovec@yandex.ru']);
    $owner->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '841']);
    $user = User::factory()->create(['phone' => '8 999 123-45-67']);
    fakeSocialUser('yandex', ['id' => '841', 'default_phone' => ['number' => '+79991234567']], 'moskovec@yandex.ru');

    $this->actingAs($user)->from(route('dashboard'))->get('/auth/yandex/link');
    $this->get('/auth/yandex/callback?code=x')->assertRedirect(route('dashboard'));

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('flash.phone_result.move', true)
        ->where('flash.phone_result.text', fn (string $text): bool => str_contains($text, 'm***@yandex.ru') && ! str_contains($text, 'moskovec')));
    expect($user->fresh()->phone_verified_at)->toBeNull();

    $this->from(route('dashboard'))->post('/auth/link/move')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('message', 'Яндекс привязан, телефон подтверждён.');

    expect($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->where('provider_user_id', '841')->exists())->toBeTrue()
        ->and($owner->socialAccounts()->exists())->toBeFalse()
        ->and($owner->fresh())->not->toBeNull();
});

it('does not move a login without a fresh confirmation from yandex', function (): void {
    $owner = User::factory()->create();
    $owner->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '851']);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))->post('/auth/link/move')
        ->assertSessionHas('error', 'Время на перенос вышло. Нажмите «Подтвердить» ещё раз.');

    expect($owner->socialAccounts()->exists())->toBeTrue();
});

it('returns to the event page after a social login started from the event registration', function (): void {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '333']);
    fakeSocialUser('yandex', ['id' => '333', 'first_name' => 'Анна'], 'anna@yandex.ru');

    $this->get('/auth/yandex/redirect?return=/events/webinar-1')->assertRedirect();
    $this->get('/auth/yandex/callback?code=x&state=y')->assertRedirect(url('/events/webinar-1'));

    $this->assertAuthenticatedAs($user);
});

it('ignores a foreign return address', function (): void {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '444']);
    fakeSocialUser('yandex', ['id' => '444', 'first_name' => 'Анна'], 'anna@yandex.ru');

    $this->get('/auth/yandex/redirect?return=//evil.example/steal')->assertRedirect();
    $this->get('/auth/yandex/redirect?return=https://evil.example')->assertRedirect();
    $this->get('/auth/yandex/callback?code=x&state=y')->assertRedirect(route('dashboard', absolute: false));
});
