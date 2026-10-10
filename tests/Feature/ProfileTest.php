<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('deleting the account anonymizes the user instead of erasing their records', function () {
    $user = User::factory()->create(['email' => 'anna@example.com', 'phone' => '+79990000000', 'city' => 'Москва', 'newsletter_consent' => true]);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();

    // запись остаётся: платежи и записи на мероприятия с каскадным удалением не пропадают
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->first_name)->toBe('Удалённый пользователь')
        ->and($fresh->last_name)->toBeNull()
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->city)->toBeNull()
        ->and($fresh->newsletter_consent)->toBeFalsy()
        ->and($fresh->email)->toBe('deleted-'.$user->id.'@mag-expert.invalid')
        ->and(Illuminate\Support\Facades\Hash::check('password', $fresh->password))->toBeFalse();

    // войти со старыми данными нельзя, а тот же email можно зарегистрировать снова
    $this->post('/login', ['email' => 'anna@example.com', 'password' => 'password']);
    $this->assertGuest();
    expect(User::query()->where('email', 'anna@example.com')->exists())->toBeFalse();
});

test('deleting the account removes the address from sender bases', function () {
    $this->migrateSenderDatabase();
    config(['sender.client.organization' => 'magexpert']);
    $organization = App\Sender\Models\Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $list = $organization->lists()->create(['name' => 'Врачи']);
    $list->contacts()->create(['organization_id' => $organization->id, 'email' => 'anna@example.com']);
    $list->contacts()->create(['organization_id' => $organization->id, 'email' => 'other@example.com']);

    $user = User::factory()->create(['email' => 'Anna@Example.com']);
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    expect($list->contacts()->pluck('email')->all())->toBe(['other@example.com']);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
