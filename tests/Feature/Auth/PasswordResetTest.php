<?php

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Sender\Contracts\SenderClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('страница «Забыли пароль» открывается', function () {
    $this->get('/forgot-password')->assertOk();
});

test('по запросу уходит ссылка, а пароль не меняется', function () {
    Notification::fake();

    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPasswordLink::class);
    expect($user->refresh()->password)->toBe($oldHash);
});

test('email ищется без учёта регистра', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/forgot-password', ['email' => '  Anna@Example.TEST ']);

    Notification::assertSentTo($user, ResetPasswordLink::class);
});

test('для незнакомого email ответ такой же, письма нет', function () {
    Notification::fake();

    $known = User::factory()->create();

    $this->post('/forgot-password', ['email' => 'nobody@example.test'])
        ->assertSessionHas('status', 'Если такой email зарегистрирован, мы отправили на него ссылку для смены пароля. Она действует 60 минут.');

    Notification::assertNothingSent();
    expect($known->exists)->toBeTrue();
});

test('страница нового пароля открывается по ссылке', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) {
        $this->get('/reset-password/'.$notification->token)->assertOk();

        return true;
    });
});

test('по ссылке можно задать свой пароль', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        expect(Hash::check('new-secret-123', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('ссылка срабатывает только один раз', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user) {
        $payload = [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ];
        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();
        $this->post('/reset-password', [...$payload, 'password' => 'other-secret-1', 'password_confirmation' => 'other-secret-1'])
            ->assertSessionHasErrors(['email' => 'Ссылка устарела или уже использована. Запросите новую на странице «Забыли пароль».']);

        expect(Hash::check('new-secret-123', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('чужой токен не подходит', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->post('/reset-password', [
        'token' => 'fake-token',
        'email' => $user->email,
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ])->assertSessionHasErrors('email');

    expect($user->refresh()->password)->toBe($oldHash);
});

test('незнакомый email на странице сброса не раскрывается', function () {
    $this->post('/reset-password', [
        'token' => 'fake-token',
        'email' => 'nobody@example.test',
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ])->assertSessionHasErrors(['email' => 'Ссылка устарела или уже использована. Запросите новую на странице «Забыли пароль».']);
});

test('короткий пароль не принимается', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'any',
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});

test('письмо со ссылкой уходит через Sender по своему шаблону', function () {
    $client = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            $this->calls[] = compact('template', 'to', 'data');

            return ['id' => 'abc', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(SenderClient::class, $client);

    $user = User::factory()->create(['first_name' => 'Анна', 'email' => 'anna@example.test']);

    $this->post('/forgot-password', ['email' => $user->email]);

    expect($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['template'])->toBe('password-reset-link')
        ->and($client->calls[0]['to'])->toBe('anna@example.test')
        ->and($client->calls[0]['data']['first_name'])->toBe('Анна')
        ->and($client->calls[0]['data']['expires_in'])->toBe(60)
        ->and($client->calls[0]['data']['reset_url'])->toContain('/reset-password/')
        ->and($client->calls[0]['data']['reset_url'])->toContain('email=anna%40example.test')
        ->and($client->calls[0]['data'])->not->toHaveKey('password');
});
