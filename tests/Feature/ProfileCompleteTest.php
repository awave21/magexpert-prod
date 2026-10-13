<?php

use App\Models\Role;
use App\Models\User;

function profileUser(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'city' => 'Москва',
        'specialization' => 'Радиология',
        'email_verified_at' => now(),
    ]);
}

it('lists what is missing for full access', function (): void {
    $user = User::factory()->unverified()->create(['city' => null, 'specialization' => '']);

    expect($user->missingProfileRequirements())->toBe(['подтвердите email', 'укажите город', 'укажите специализацию'])
        ->and(profileUser()->missingProfileRequirements())->toBe([]);
});

it('sends a user with an incomplete profile from the assistant to the cabinet summary', function (): void {
    $user = profileUser(['city' => null]);

    $this->actingAs($user)->get('/cabinet/assistant')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('message', 'Чтобы открыть этот раздел, укажите город.');
});

it('answers an ajax request of an incomplete profile with 403', function (): void {
    $user = profileUser(['email_verified_at' => null]);

    $this->actingAs($user)->postJson('/cabinet/assistant/ask', ['question' => 'Привет'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Чтобы открыть этот раздел, подтвердите email.');
});

it('opens the sections for a complete profile and for staff', function (): void {
    $this->actingAs(profileUser())->get('/cabinet/assistant')->assertSuccessful();

    $staff = User::factory()->create(['city' => null, 'specialization' => null]);
    $staff->roles()->attach(Role::query()->firstOrCreate(['name' => 'admin'], ['display_name' => 'Админ'])->id);

    $this->actingAs($staff)->get('/cabinet/assistant')->assertSuccessful();
});
