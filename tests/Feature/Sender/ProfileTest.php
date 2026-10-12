<?php

use App\Sender\Mcp\McpTokens;
use App\Sender\Models\AuditLog;
use App\Sender\Models\McpToken;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\SocialLoginService;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->user = User::create(['organization_id' => $this->organization->id, 'name' => 'Ольга', 'email' => 'olga@example.com', 'password' => 'secret-pass', 'role' => 'admin']);
    $this->token = (new AuthService)->login('olga@example.com', 'secret-pass')['token'];
});

function profileApi(string $path = ''): string
{
    return '/api/sender/v1/admin/profile'.$path;
}

it('shows the profile in the same shape as /me plus linked sign-in methods', function (): void {
    $this->user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '42']);

    $this->withToken($this->token)->getJson(profileApi())
        ->assertOk()
        ->assertJsonPath('data.name', 'Ольга')
        ->assertJsonPath('data.email', 'olga@example.com')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.organization', 'MagExpert')
        ->assertJsonPath('providers', ['yandex']);
});

it('updates the name and returns the user like /me', function (): void {
    $this->withToken($this->token)->putJson(profileApi(), ['name' => '  Ольга Петрова  '])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ольга Петрова')
        ->assertJsonPath('data.organization', 'MagExpert');

    expect($this->user->fresh()->name)->toBe('Ольга Петрова');

    $this->withToken($this->token)->putJson(profileApi(), ['name' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'Укажите имя']);
});

it('does not let the email be changed from the profile', function (): void {
    $this->withToken($this->token)->putJson(profileApi(), ['name' => 'Ольга', 'email' => 'other@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Email изменить нельзя']);

    expect($this->user->fresh()->email)->toBe('olga@example.com');
});

it('rejects a wrong current password', function (): void {
    $this->withToken($this->token)->putJson(profileApi('/password'), [
        'current_password' => 'wrong-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'Текущий пароль указан неверно']);

    expect((new AuthService)->login('olga@example.com', 'secret-pass'))->not->toBeNull();
});

it('validates the new password length and confirmation', function (): void {
    $this->withToken($this->token)->putJson(profileApi('/password'), [
        'current_password' => 'secret-pass',
        'password' => 'short',
        'password_confirmation' => 'other',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('changes the password, closes other sessions and keeps the current one', function (): void {
    $other = (new AuthService)->login('olga@example.com', 'secret-pass')['token'];

    $this->withToken($this->token)->putJson(profileApi('/password'), [
        'current_password' => 'secret-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ])->assertOk();

    expect((new AuthService)->login('olga@example.com', 'secret-pass'))->toBeNull()
        ->and((new AuthService)->login('olga@example.com', 'new-secret-pass'))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'profile.password')->where('user_id', $this->user->id)->exists())->toBeTrue();

    $this->withToken($other)->getJson('/api/sender/v1/admin/me')->assertUnauthorized();
    $this->withToken($this->token)->getJson('/api/sender/v1/admin/me')->assertOk();
});

it('lets a viewer edit their own name and password', function (): void {
    User::create(['organization_id' => $this->organization->id, 'name' => 'Зритель', 'email' => 'viewer@example.com', 'password' => 'secret-pass', 'role' => 'viewer']);
    $viewer = (new AuthService)->login('viewer@example.com', 'secret-pass')['token'];

    $this->withToken($viewer)->postJson('/api/sender/v1/admin/lists', ['name' => 'Новая'])->assertForbidden();

    $this->withToken($viewer)->putJson(profileApi(), ['name' => 'Новое имя'])
        ->assertOk()->assertJsonPath('data.name', 'Новое имя')->assertJsonPath('data.role', 'viewer');

    $this->withToken($viewer)->putJson(profileApi('/password'), [
        'current_password' => 'secret-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ])->assertOk();

    expect((new AuthService)->login('viewer@example.com', 'new-secret-pass'))->not->toBeNull();
});

it('changes only the signed-in user and ignores attempts to target someone else', function (): void {
    $other = User::create(['organization_id' => $this->organization->id, 'name' => 'Иван', 'email' => 'ivan@example.com', 'password' => 'ivan-pass', 'role' => 'editor']);

    $this->withToken($this->token)->putJson(profileApi(), ['name' => 'Ольга П.', 'id' => $other->id, 'role' => 'viewer'])->assertOk();
    $this->withToken($this->token)->putJson(profileApi('/password'), [
        'current_password' => 'secret-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
        'id' => $other->id,
    ])->assertOk();

    $other->refresh();
    expect($other->name)->toBe('Иван')
        ->and($other->role)->toBe('editor')
        ->and((new AuthService)->login('ivan@example.com', 'ivan-pass'))->not->toBeNull()
        ->and($this->user->fresh()->role)->toBe('admin');
});

it('requires sign-in', function (): void {
    $this->putJson(profileApi(), ['name' => 'Кто-то'])->assertUnauthorized();
    $this->putJson(profileApi('/password'), [])->assertUnauthorized();
});

it('exposes whether the password is set in /me and /profile', function (): void {
    $this->withToken($this->token)->getJson('/api/sender/v1/admin/me')->assertOk()->assertJsonPath('data.password_set', true);
    $this->withToken($this->token)->getJson(profileApi())->assertOk()->assertJsonPath('data.password_set', true);
});

it('revokes all MCP keys when the password changes', function (): void {
    $keys = app(McpTokens::class);
    $first = $keys->issue($this->user, 'Ноутбук')['plain'];
    $keys->issue($this->user, 'Телефон');
    $stranger = User::create(['organization_id' => $this->organization->id, 'name' => 'Иван', 'email' => 'ivan@example.com', 'password' => 'ivan-pass', 'role' => 'editor']);
    $keys->issue($stranger, 'Чужой');

    $this->withToken($this->token)->putJson(profileApi('/password'), [
        'current_password' => 'secret-pass',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ])->assertOk()->assertJsonPath('message', 'Пароль изменён. На других устройствах нужно войти заново, ключи MCP отозваны');

    expect(McpToken::query()->where('user_id', $this->user->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(McpToken::query()->where('user_id', $this->user->id)->whereNotNull('revoked_at')->count())->toBe(2)
        ->and(McpToken::query()->where('user_id', $stranger->id)->whereNull('revoked_at')->count())->toBe(1);

    $this->withToken($first)->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
});

it('lets a user registered through Yandex or VK set a password without the current one', function (): void {
    config(['sender.registration' => true]);
    $social = app(SocialLoginService::class);
    $code = $social->handle(['provider' => 'yandex', 'id' => '777', 'email' => 'anna@clinic.ru', 'name' => 'Анна'])['code'];
    $result = $social->register($code, 'Клиника', 'anna@clinic.ru');
    $user = $result['user']->fresh();
    $token = $result['token'];

    expect($user->password_set)->toBeFalse()
        ->and($user->hasPassword())->toBeFalse();

    $this->withToken($token)->getJson('/api/sender/v1/admin/me')->assertOk()->assertJsonPath('data.password_set', false);
    $this->withToken($token)->getJson(profileApi())->assertOk()->assertJsonPath('data.password_set', false);
    $mcp = app(McpTokens::class)->issue($user, 'Ключ')['plain'];

    // без текущего пароля, но с проверкой нового
    $this->withToken($token)->putJson(profileApi('/password'), ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password'])->assertJsonMissingValidationErrors(['current_password']);

    $this->withToken($token)->putJson(profileApi('/password'), ['password' => 'anna-new-pass', 'password_confirmation' => 'anna-new-pass'])
        ->assertOk()->assertJsonPath('message', 'Пароль задан. Теперь можно входить по email и паролю');

    expect($user->fresh()->password_set)->toBeTrue()
        ->and((new AuthService)->login('anna@clinic.ru', 'anna-new-pass'))->not->toBeNull()
        ->and(McpToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->exists())->toBeFalse();
    $this->withToken($mcp)->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();

    // теперь пароль есть: следующая смена — только с текущим
    $this->withToken($token)->getJson('/api/sender/v1/admin/me')->assertJsonPath('data.password_set', true);
    $this->withToken($token)->putJson(profileApi('/password'), ['password' => 'another-pass', 'password_confirmation' => 'another-pass'])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'Укажите текущий пароль']);
});
