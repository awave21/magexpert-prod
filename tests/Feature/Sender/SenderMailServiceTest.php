<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Sender\SenderClient;
use App\Services\SenderMailService;

beforeEach(function (): void {
    $this->client = new class implements SenderClient
    {
        public array $calls = [];

        public string $status = 'queued';

        /** @var list<string> */
        public array $rejected = [];

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null): array
        {
            $this->calls[] = compact('template', 'to', 'data', 'idempotencyKey');

            return ['id' => 'abc', 'status' => in_array($template, $this->rejected, true) ? 'failed' : $this->status, 'error' => null];
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
        ]);
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
        ->and($data)->not->toHaveKeys(['event_location', 'price', 'speakers', 'speakers_text', 'end_date']);
});

it('returns false when the sender rejects the message', function (): void {
    $this->client->status = 'blocked';

    expect($this->service->sendApiRegistrationEmail('a@b.ru', 'pw'))->toBeFalse();
});

it('returns false instead of throwing when the client fails', function (): void {
    $service = new SenderMailService(new class implements SenderClient
    {
        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null): array
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
    config(['services.sender.templates.welcome' => '42']);

    $this->service->sendWelcomeEmail(new User(['first_name' => 'Анна', 'email' => 'a@b.ru']));

    expect($this->client->calls[0]['template'])->toBe('42');
});

it('passes the event image, schedule and speakers as separate variables', function (): void {
    $event = Event::make([
        'title' => 'Конференция',
        'slug' => 'conf',
        'format' => 'offline',
        'location' => 'Москва, ул. Примерная, 1',
        'image' => '/storage/events/cover.webp',
        'short_description' => '<p>Практика</p>',
        'is_archived' => false,
        'is_paid' => false,
        'show_price' => false,
        'start_date' => '2026-11-15',
        'start_time' => '10:00:00',
    ]);
    $event->setRelation('speakers', collect([
        new \App\Models\Speaker(['first_name' => 'Иван', 'last_name' => 'Иванов', 'position' => 'Профессор', 'company' => 'Клиника', 'photo' => '/storage/speakers/1.webp']),
        new \App\Models\Speaker(['first_name' => 'Пётр', 'last_name' => 'Сидоров', 'photo' => 'https://cdn.example.com/p.webp']),
    ]));

    $user = User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']);

    expect($this->service->sendEventRegistrationEmail($event, $user))->toBeTrue();

    $data = $this->client->calls[0]['data'];

    expect($data)->toMatchArray([
        'event_image' => url('/storage/events/cover.webp'),
        'event_description' => 'Практика',
        'event_location' => 'Москва, ул. Примерная, 1',
        'event_datetime' => '15.11.2026, 10:00',
        'speakers_count' => 2,
    ])->and($data['speakers'])->toHaveCount(2)
        ->and($data['speakers'][0]['name'])->toContain('Иванов')
        ->and($data['speakers'])->toBe([
            ['name' => $data['speakers'][0]['name'], 'position' => 'Профессор', 'company' => 'Клиника', 'photo' => url('/storage/speakers/1.webp')],
            ['name' => $data['speakers'][1]['name'], 'photo' => 'https://cdn.example.com/p.webp'],
        ])
        ->and(array_keys($data))->not->toContain('speaker_1_name', 'speaker_1_photo', 'speaker_2_name');
});

it('passes every speaker in the speakers list without the six-speaker limit', function (): void {
    $event = Event::make(['title' => 'Школа', 'slug' => 'school', 'format' => 'online', 'is_archived' => false, 'is_paid' => false, 'show_price' => false]);
    $event->setRelation('speakers', collect(range(1, 8))->map(
        fn (int $number): \App\Models\Speaker => new \App\Models\Speaker(['first_name' => 'Спикер', 'last_name' => (string) $number, 'photo' => "/storage/speakers/{$number}.webp"]),
    ));

    $this->service->sendEventRegistrationEmail($event, User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']));

    $data = $this->client->calls[0]['data'];

    expect($data['speakers'])->toHaveCount(8)
        ->and($data['speakers_count'])->toBe(8)
        ->and($data['speakers'][7]['photo'])->toBe(url('/storage/speakers/8.webp'))
        ->and(array_keys($data))->not->toContain('speaker_1_name', 'speaker_7_name');
});

it('passes the city and speaker regalia and leaves empty fields out', function (): void {
    $event = Event::make([
        'title' => 'Школа', 'slug' => 'school', 'format' => 'offline', 'city' => 'Казань',
        'is_archived' => false, 'is_paid' => false, 'show_price' => false,
    ]);
    $event->setRelation('speakers', collect([
        new \App\Models\Speaker(['first_name' => 'Иван', 'last_name' => 'Иванов', 'regalia' => 'Д.м.н., профессор']),
    ]));

    $this->service->sendEventRegistrationEmail($event, User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']));

    $data = $this->client->calls[0]['data'];

    expect($data['event_city'])->toBe('Казань')
        ->and($data['speakers'])->toBe([['name' => $data['speakers'][0]['name'], 'regalia' => 'Д.м.н., профессор']])
        ->and($data['speakers_text'])->toContain('Д.м.н., профессор')
        ->and($data)->not->toHaveKeys(['event_image', 'event_location']);
});

it('sends the event letter by its own template id or alias and falls back to the standard one', function (): void {
    $event = Event::make([
        'title' => 'Школа', 'slug' => 'school', 'format' => 'online', 'is_archived' => false, 'is_paid' => false, 'show_price' => false,
        'letter_draft_id' => 'draft-12',
    ]);
    $event->setRelation('speakers', collect());
    $user = User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']);

    expect($this->service->sendEventRegistrationEmail($event, $user))->toBeTrue()
        ->and(array_column($this->client->calls, 'template'))->toBe(['draft-12']);

    // такого шаблона в Sender нет: письмо не теряется, уходит стандартное
    $this->client->calls = [];
    $this->client->rejected = ['draft-12'];

    expect($this->service->sendEventRegistrationEmail($event, $user))->toBeTrue()
        ->and(array_column($this->client->calls, 'template'))->toBe(['draft-12', 'event-registration']);

    // без своего шаблона — сразу стандартное
    $this->client->calls = [];
    $event->letter_draft_id = null;

    expect($this->service->sendEventRegistrationEmail($event, $user))->toBeTrue()
        ->and(array_column($this->client->calls, 'template'))->toBe(['event-registration']);
});

it('sends the registration letter with a daily idempotency key', function (): void {
    $event = Event::make(['title' => 'Школа', 'slug' => 'school', 'format' => 'online', 'is_archived' => false, 'is_paid' => false, 'show_price' => false]);
    $event->id = 63;
    $event->setRelation('speakers', collect());
    $user = User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']);
    $user->id = 7;

    $this->service->sendEventRegistrationEmail($event, $user);

    expect($this->client->calls[0]['idempotencyKey'])->toBe('event-registration:63:7:event-registration:'.now()->format('Y-m-d'));
});
