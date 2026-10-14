<?php

use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\Sender\SenderClient;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->client = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null): array
        {
            $this->calls[] = compact('template', 'to', 'data');

            return ['id' => 'x', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(SenderClient::class, $this->client);
});

function guestRegistration(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Анна', 'last_name' => 'Иванова', 'email' => 'new.guest@example.com', 'phone' => '+79990000000',
        'privacy_consent' => true, 'oferta_consent' => true,
    ], $overrides);
}

it('registers a new guest without sending a password: only a set-password link', function (): void {
    $event = Event::factory()->create(['is_paid' => false, 'registration_enabled' => true, 'is_active' => true]);

    $this->post("/events/{$event->slug}/register", guestRegistration())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.guest@example.com')->firstOrFail();
    $mail = collect($this->client->calls)->firstWhere('template', 'event-registration');

    expect($mail)->not->toBeNull()
        ->and($mail['data'])->not->toHaveKey('password')
        ->and($mail['data']['is_new_user'])->toBeTrue()
        ->and($mail['data']['set_password_url'])->toContain('/reset-password/')
        ->and($mail['data']['set_password_url'])->toContain(rawurlencode('new.guest@example.com'))
        // пароль случайный и нигде не показан: подобрать его по данным письма нельзя
        ->and(Hash::check(json_encode($mail['data']), $user->password))->toBeFalse();
});

it('does not keep a generated password in the metadata of a payment', function (): void {
    $event = Event::factory()->create(['is_paid' => true, 'price' => 1500, 'show_price' => true, 'registration_enabled' => true, 'is_active' => true]);

    $this->post("/events/{$event->slug}/register", guestRegistration(['email' => 'payer@example.com']));

    $payment = Payment::query()->latest('id')->first();

    expect($payment)->not->toBeNull('платёж не создан: тест ничего не проверяет')
        ->and($payment->metadata)->not->toHaveKey('generated_password')
        ->and($payment->metadata['is_new_user'] ?? null)->toBeTrue();
});

it('creates an api user with an unusable password and a set-password link', function (): void {
    $this->postJson('/api/users', [
        'first_name' => 'Пётр', 'last_name' => 'Сидоров', 'email' => 'api.user@example.com', 'phone' => '+79991112233',
        'privacy_consent' => true, 'oferta_consent' => true, 'newsletter_consent' => true,
    ])->assertCreated()->assertJsonMissingPath('data.password');

    $mail = collect($this->client->calls)->firstWhere('template', 'api-registration');

    expect($mail)->not->toBeNull()
        ->and($mail['data'])->not->toHaveKey('password')
        ->and($mail['data']['set_password_url'])->toContain('/reset-password/');
});
