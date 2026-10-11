<?php

use App\Models\Event;
use App\Models\User;
use App\Sender\Contracts\SenderClient;
use App\Services\SenderMailService;

beforeEach(function (): void {
    $this->client = new class implements SenderClient
    {
        public array $calls = [];

        public string $status = 'queued';

        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            $this->calls[] = compact('template', 'to', 'from', 'fromName', 'data');

            return ['id' => 'abc', 'status' => $this->status, 'error' => null];
        }
    };

    $this->service = new SenderMailService($this->client);
});

it('sends a password reset link without a password', function (): void {
    $user = User::make(['first_name' => 'Анна', 'email' => 'User@Example.com']);

    $sent = $this->service->sendPasswordResetLink($user, 'https://mag-expert.ru/reset-password/token', 60);

    expect($sent)->toBeTrue()
        ->and($this->client->calls[0]['template'])->toBe('password-reset-link')
        ->and($this->client->calls[0]['to'])->toBe('User@Example.com')
        ->and($this->client->calls[0]['data'])->toBe([
            'first_name' => 'Анна',
            'user_email' => 'user@example.com',
            'reset_url' => 'https://mag-expert.ru/reset-password/token',
            'expires_in' => 60,
        ])
        ->and($this->client->calls[0]['from'])->toBe(config('sender.client.from_address'));
});

it('sends an api registration email', function (): void {
    expect($this->service->sendApiRegistrationEmail('a@b.ru', 'pw', 'Анна'))->toBeTrue()
        ->and($this->client->calls[0]['template'])->toBe('api-registration');
});

it('sends an event registration email with only filled fields', function (): void {
    $event = Event::make([
        'title' => 'Вебинар',
        'slug' => 'webinar',
        'event_type' => 'webinar',
        'format' => 'online',
        'is_archived' => false,
        'is_paid' => false,
        'show_price' => false,
        'start_date' => '2026-10-16',
        'start_time' => '12:00:00',
    ]);
    $event->setRelation('speakers', collect());

    $user = User::make(['first_name' => 'Анна', 'last_name' => 'Петрова', 'email' => 'anna@example.com']);

    expect($this->service->sendEventRegistrationEmail($event, $user, 'pw', true))->toBeTrue();

    $data = $this->client->calls[0]['data'];

    expect($this->client->calls[0]['template'])->toBe('event-registration')
        ->and($data)->toMatchArray([
            'user_name' => 'Анна Петрова',
            'first_name' => 'Анна',
            'is_new_user' => true,
            'password' => 'pw',
            'event_title' => 'Вебинар',
            'event_type' => 'Вебинар',
            'event_format' => 'Онлайн',
            'start_date' => '16.10.2026',
            'start_time' => '12:00',
        ])
        ->and($data)->not->toHaveKeys(['event_location', 'price', 'speakers', 'end_date']);
});

it('returns false when the sender rejects the message', function (): void {
    $this->client->status = 'blocked';

    expect($this->service->sendApiRegistrationEmail('a@b.ru', 'pw'))->toBeFalse();
});

it('returns false instead of throwing when the client fails', function (): void {
    $service = new SenderMailService(new class implements SenderClient
    {
        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            throw new RuntimeException('Sender недоступен');
        }
    });

    expect($service->sendApiRegistrationEmail('a@b.ru', 'pw'))->toBeFalse();
});

it('sends a welcome email after sign-up without a password', function (): void {
    $user = new User(['first_name' => 'Анна', 'last_name' => 'Иванова', 'email' => 'Anna@Example.com']);

    expect($this->service->sendWelcomeEmail($user))->toBeTrue()
        ->and($this->client->calls[0]['template'])->toBe('welcome')
        ->and($this->client->calls[0]['data'])->toMatchArray(['first_name' => 'Анна', 'name' => 'Анна Иванова', 'user_email' => 'anna@example.com'])
        ->and($this->client->calls[0]['data'])->not->toHaveKey('password');
});

it('uses the template id or alias from the config', function (): void {
    config(['sender.client.templates.welcome' => '42']);

    $this->service->sendWelcomeEmail(new User(['first_name' => 'Анна', 'email' => 'a@b.ru']));

    expect($this->client->calls[0]['template'])->toBe('42');
});
