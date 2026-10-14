<?php

use App\Jobs\DeliverOutboxMail;
use App\Models\MailOutbox;
use App\Services\Sender\SenderClient;
use Illuminate\Support\Facades\Queue;

function outboxEntry(array $overrides = []): MailOutbox
{
    return MailOutbox::query()->create(array_merge([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'template' => 'welcome',
        'to_email' => 'anna@example.com',
        'data' => ['first_name' => 'Анна', 'verify_url' => 'https://x.test/verify/abc', 'reset_url' => 'https://x.test/reset/abc'],
        'idempotency_key' => 'k-'.Illuminate\Support\Str::random(8),
        'status' => MailOutbox::STATUS_PENDING,
    ], $overrides));
}

function fakeSender(array $responses): object
{
    $client = new class($responses) implements SenderClient
    {
        public array $calls = [];

        public function __construct(private array $responses) {}

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array
        {
            $this->calls[] = compact('template', 'idempotencyKey', 'requestId');
            $next = array_shift($this->responses);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }
    };
    app()->instance(SenderClient::class, $client);

    return $client;
}

it('delivers a pending letter, stores the Sender message id and erases one-time links', function (): void {
    $client = fakeSender([['id' => 'msg-1', 'status' => 'queued', 'error' => null, 'http' => 202]]);
    $entry = outboxEntry(['idempotency_key' => 'reg-1']);

    DeliverOutboxMail::dispatchSync($entry->id);

    $entry->refresh();

    expect($entry->status)->toBe(MailOutbox::STATUS_SENT)
        ->and($entry->sender_message_id)->toBe('msg-1')
        ->and($entry->data)->toBe(['first_name' => 'Анна'])
        ->and($client->calls[0]['idempotencyKey'])->toBe('reg-1')
        // идентификатор письма в исходящих уходит в Sender как X-Request-Id: по нему письмо находят в журнале Sender
        ->and($client->calls[0]['requestId'])->toBe($entry->uuid);
});

it('keeps a letter pending and asks the queue to retry after a server error', function (): void {
    $client = fakeSender([['id' => null, 'status' => 'failed', 'error' => 'HTTP 503', 'http' => 503]]);
    $entry = outboxEntry(['idempotency_key' => 'reg-2']);

    expect(fn () => (new DeliverOutboxMail($entry->id))->handle($client))->toThrow(RuntimeException::class);

    expect($entry->fresh()->status)->toBe(MailOutbox::STATUS_PENDING)
        ->and($entry->fresh()->attempts)->toBe(1)
        ->and($entry->fresh()->last_error)->toBe('HTTP 503')
        ->and($client->calls[0]['idempotencyKey'])->toBe('reg-2');
});

it('marks a letter failed when Sender rejects it for good and does not retry', function (): void {
    $client = fakeSender([['id' => null, 'status' => 'failed', 'error' => 'Шаблон не найден', 'http' => 404]]);
    $entry = outboxEntry();

    (new DeliverOutboxMail($entry->id))->handle($client);

    $entry->refresh();

    expect($entry->status)->toBe(MailOutbox::STATUS_FAILED)
        ->and($entry->last_error)->toBe('Шаблон не найден')
        ->and($entry->data)->not->toHaveKey('verify_url')
        ->and($client->calls)->toHaveCount(1);
});

it('falls back to the standard template only for a permanent rejection', function (): void {
    $client = fakeSender([
        ['id' => null, 'status' => 'failed', 'error' => 'Шаблон не найден', 'http' => 404],
        ['id' => 'msg-9', 'status' => 'queued', 'error' => null, 'http' => 202],
    ]);
    $entry = outboxEntry(['template' => 'draft-12', 'fallback_template' => 'event-registration', 'idempotency_key' => 'reg-3']);

    (new DeliverOutboxMail($entry->id))->handle($client);

    expect(array_column($client->calls, 'template'))->toBe(['draft-12', 'event-registration'])
        ->and($client->calls[1]['idempotencyKey'])->toBe('reg-3:fallback')
        ->and($entry->fresh()->status)->toBe(MailOutbox::STATUS_SENT);
});

it('retries without falling back when Sender is unavailable', function (): void {
    $client = fakeSender([['id' => null, 'status' => 'failed', 'error' => 'HTTP 502', 'http' => 502]]);
    $entry = outboxEntry(['template' => 'draft-12', 'fallback_template' => 'event-registration']);

    try {
        (new DeliverOutboxMail($entry->id))->handle($client);
    } catch (RuntimeException) {
    }

    expect(array_column($client->calls, 'template'))->toBe(['draft-12'])
        ->and($entry->fresh()->status)->toBe(MailOutbox::STATUS_PENDING)
        ->and($entry->fresh()->next_attempt_at)->not->toBeNull();
});

it('does not deliver a letter that is already sent', function (): void {
    $client = fakeSender([]);
    $entry = outboxEntry(['status' => MailOutbox::STATUS_SENT]);

    DeliverOutboxMail::dispatchSync($entry->id);

    expect($client->calls)->toBe([]);
});

it('mail:outbox-retry requeues stuck letters and failed ones only on request', function (): void {
    Queue::fake();
    $stuck = outboxEntry();
    $stuck->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $fresh = outboxEntry();
    $failed = outboxEntry(['status' => MailOutbox::STATUS_FAILED]);

    $this->artisan('mail:outbox-retry')->assertSuccessful();

    Queue::assertPushed(DeliverOutboxMail::class, 1);
    Queue::assertPushed(DeliverOutboxMail::class, fn (DeliverOutboxMail $job) => $job->outboxId === $stuck->id);

    $this->artisan('mail:outbox-retry', ['--failed' => true])->assertSuccessful();

    Queue::assertPushed(DeliverOutboxMail::class, fn (DeliverOutboxMail $job) => $job->outboxId === $failed->id);
    expect($fresh->fresh()->status)->toBe(MailOutbox::STATUS_PENDING);
});

it('mail:outbox-prune removes only old sent letters', function (): void {
    config(['services.sender.outbox_days' => 30]);
    $old = outboxEntry(['status' => MailOutbox::STATUS_SENT, 'sent_at' => now()->subDays(31)]);
    $recent = outboxEntry(['status' => MailOutbox::STATUS_SENT, 'sent_at' => now()->subDays(2)]);
    $failed = outboxEntry(['status' => MailOutbox::STATUS_FAILED]);

    $this->artisan('mail:outbox-prune')->assertSuccessful();

    expect(MailOutbox::query()->pluck('id')->all())->toEqualCanonicalizing([$recent->id, $failed->id])
        ->and(MailOutbox::query()->whereKey($old->id)->exists())->toBeFalse();
});
