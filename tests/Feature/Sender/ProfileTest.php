<?php

use App\Sender\Models\AuditLog;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;

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
