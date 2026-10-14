<?php

use App\Models\User;

function senderWebhook(array $payload, ?string $secret = 'topsecret', ?string $signature = null)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $signature ??= 'sha256='.hash_hmac('sha256', $body, (string) $secret);

    return test()->call('POST', '/api/webhooks/sender', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SENDER_SIGNATURE' => $signature,
    ], $body);
}

beforeEach(function (): void {
    config(['services.sender.webhook_secret' => 'topsecret', 'services.sender.users_sync' => false]);
});

it('withdraws the newsletter consent when Sender reports an unsubscribe', function (): void {
    $user = User::factory()->create(['email' => 'anna@example.com', 'newsletter_consent' => true]);
    $other = User::factory()->create(['email' => 'petr@example.com', 'newsletter_consent' => true]);

    senderWebhook(['event' => 'recipient.unsubscribed', 'occurred_at' => now()->toIso8601String(), 'email' => 'Anna@Example.com'])
        ->assertNoContent();

    expect($user->fresh()->newsletter_consent)->toBeFalse()
        ->and($user->fresh()->newsletter_withdrawn_at)->not->toBeNull()
        ->and($other->fresh()->newsletter_consent)->toBeTrue();
});

it('withdraws the consent after a spam complaint', function (): void {
    $user = User::factory()->create(['email' => 'anna@example.com', 'newsletter_consent' => true]);

    senderWebhook(['event' => 'message.complained', 'occurred_at' => now()->toIso8601String(), 'message' => ['id' => 'x', 'to' => 'anna@example.com', 'subject' => 'S', 'status' => 'delivered']])
        ->assertNoContent();

    expect($user->fresh()->newsletter_consent)->toBeFalse();
});

it('ignores events that do not affect the consent', function (): void {
    $user = User::factory()->create(['email' => 'anna@example.com', 'newsletter_consent' => true]);

    senderWebhook(['event' => 'message.opened', 'occurred_at' => now()->toIso8601String(), 'message' => ['to' => 'anna@example.com']])
        ->assertNoContent();

    expect($user->fresh()->newsletter_consent)->toBeTrue();
});

it('rejects a wrong or missing signature and an unset secret', function (): void {
    $user = User::factory()->create(['email' => 'anna@example.com', 'newsletter_consent' => true]);
    $payload = ['event' => 'recipient.unsubscribed', 'email' => 'anna@example.com'];

    senderWebhook($payload, signature: 'sha256=deadbeef')->assertUnauthorized();
    senderWebhook($payload, secret: 'another')->assertUnauthorized();

    config(['services.sender.webhook_secret' => null]);
    senderWebhook($payload, secret: '')->assertUnauthorized();

    expect($user->fresh()->newsletter_consent)->toBeTrue();
});
