<?php

use App\Models\User;
use App\Sender\Contracts\SenderClient;
use App\Services\EmailConfirmation;

beforeEach(function (): void {
    $this->sent = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            $this->calls[] = compact('template', 'to', 'data');

            return ['id' => 'x', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(SenderClient::class, $this->sent);
});

it('puts the confirmation link into the welcome email of a form sign-up', function (): void {
    $this->post('/register', ['first_name' => 'Анна', 'last_name' => 'Иванова', 'email' => 'anna@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    $url = $this->sent->calls[0]['data']['verify_url'] ?? null;

    expect($this->sent->calls[0]['template'])->toBe('welcome')
        ->and($url)->toContain('/email/confirm/')
        ->and($url)->toContain('signature=');
});

it('confirms the email by the link even without logging in', function (): void {
    $user = User::factory()->unverified()->create();
    $url = app(EmailConfirmation::class)->url($user);

    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('message', 'Email подтверждён. Войдите в аккаунт.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a forged, expired or outdated link', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
    $url = app(EmailConfirmation::class)->url($user);

    $this->get($url.'x')->assertSessionHas('error');

    $this->travel(8)->days();
    $this->get($url)->assertSessionHas('error');
    $this->travelBack();

    $user->update(['email' => 'new@example.com']);
    $this->get($url)->assertSessionHas('error');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the link from the cabinet and does not include it for verified users', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/confirm/resend')->assertSessionHas('message');

    expect($this->sent->calls[0]['template'])->toBe('email-confirmation')
        ->and($this->sent->calls[0]['data']['verify_url'])->toContain('/email/confirm/'.$user->id.'/');

    $verified = User::factory()->create();
    app(App\Services\SenderMailService::class)->sendWelcomeEmail($verified);

    expect($this->sent->calls[1]['data'])->not->toHaveKey('verify_url');
});
