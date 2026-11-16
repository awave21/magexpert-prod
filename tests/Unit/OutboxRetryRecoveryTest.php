<?php

use App\Jobs\DeliverOutboxMail;
use App\Models\Event;
use App\Models\MailOutbox;
use App\Models\User;
use App\Services\Sender\SenderClient;
use App\Services\SenderMailService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'database.default' => 'outbox_test',
        'database.connections.outbox_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.default' => 'sync',
    ]);
    (require base_path('database/migrations/2026_10_14_180000_create_mail_outbox_table.php'))->up();

    $this->client = new class implements SenderClient
    {
        public bool $unavailable = true;

        public string $status = 'queued';

        public int $calls = 0;

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array
        {
            $this->calls++;

            if ($this->unavailable) {
                throw new RuntimeException('Sender недоступен');
            }

            return ['id' => 'recovered-message', 'status' => $this->status, 'error' => null];
        }
    };
    app()->instance(SenderClient::class, $this->client);
});

it('recovers a registration letter after a synchronous delivery failure without creating a duplicate', function (): void {
    $event = new Event(['title' => 'Вебинар', 'slug' => 'webinar', 'format' => 'online', 'show_price' => false, 'is_archived' => false]);
    $event->id = 63;
    $event->setRelation('speakers', collect());
    $user = new User(['first_name' => 'Анна', 'email' => 'anna@example.com']);
    $user->id = 7;
    $service = app(SenderMailService::class);

    expect($service->sendEventRegistrationEmail($event, $user))->toBeTrue();
    $entry = MailOutbox::query()->sole();
    expect($entry->status)->toBe(MailOutbox::STATUS_PENDING)
        ->and($entry->next_attempt_at)->not->toBeNull()
        ->and($entry->data['event_title'])->toBe('Вебинар');

    $this->travel(6)->minutes();
    $this->client->unavailable = false;
    $this->artisan('mail:outbox-retry')->assertSuccessful();

    expect($entry->fresh()->status)->toBe(MailOutbox::STATUS_SENT)
        ->and($entry->fresh()->sender_message_id)->toBe('recovered-message');
    $service->sendEventRegistrationEmail($event, $user);
    expect(MailOutbox::query()->count())->toBe(1)
        ->and($this->client->calls)->toBe(2);
});

it('keeps retryable letters recoverable when an asynchronous worker exhausts its retries', function (): void {
    $entry = MailOutbox::query()->create([
        'uuid' => (string) Str::uuid(), 'idempotency_key' => 'registration-retry',
        'template' => 'event-registration', 'to_email' => 'anna@example.com',
        'status' => MailOutbox::STATUS_PENDING, 'data' => ['event_title' => 'Вебинар'],
    ]);
    $job = new DeliverOutboxMail($entry->id);
    expect(fn () => $job->handle($this->client))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('Worker retries exhausted'));

    expect($entry->fresh()->status)->toBe(MailOutbox::STATUS_PENDING)
        ->and($entry->fresh()->data['event_title'])->toBe('Вебинар');

    $this->travel(6)->minutes();
    Queue::fake();
    $this->artisan('mail:outbox-retry')->assertSuccessful();
    Queue::assertPushed(DeliverOutboxMail::class, fn (DeliverOutboxMail $job): bool => $job->outboxId === $entry->id);
});

it('returns false for a permanently rejected letter and does not automatically retry it', function (): void {
    $this->client->unavailable = false;
    $this->client->status = 'blocked';
    expect(app(SenderMailService::class)->sendWelcomeEmail(new User(['email' => 'anna@example.com'])))->toBeFalse();
    $entry = MailOutbox::query()->sole();
    expect($entry->status)->toBe(MailOutbox::STATUS_FAILED);

    $this->travel(6)->minutes();
    Queue::fake();
    $this->artisan('mail:outbox-retry')->assertSuccessful();
    Queue::assertNothingPushed();
});
