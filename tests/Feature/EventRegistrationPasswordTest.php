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

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array
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
        'password' => 'Secret-pass-2026', 'password_confirmation' => 'Secret-pass-2026',
        'privacy_consent' => true, 'oferta_consent' => true,
    ], $overrides);
}

it('registers a new guest without account details in the event letter', function (): void {
    $event = Event::factory()->create(['is_paid' => false, 'registration_enabled' => true, 'is_active' => true]);

    $this->post("/events/{$event->slug}/register", guestRegistration())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.guest@example.com')->firstOrFail();
    $mail = collect($this->client->calls)->firstWhere('template', 'event-registration');

    expect($mail)->not->toBeNull()
        // письмо о регистрации только про мероприятие: ни пароля, ни ссылки «Задать пароль», ни «Мы создали учётную запись»
        ->and(array_keys($mail['data']))->not->toContain('password', 'is_new_user', 'set_password_url', 'set_password_expires_in')
        ->and($this->isAuthenticated())->toBeTrue()
        // войти можно своим паролем из формы, как после обычной регистрации
        ->and(Hash::check('Secret-pass-2026', $user->password))->toBeTrue()
        // пароль случайный и нигде не показан: подобрать его по данным письма нельзя
        ->and(Hash::check(json_encode($mail['data']), $user->password))->toBeFalse();
});

it('does not register a new guest without a password or with a mismatched one', function (): void {
    $event = Event::factory()->create(['is_paid' => false, 'registration_enabled' => true, 'is_active' => true]);

    $this->post("/events/{$event->slug}/register", guestRegistration(['password' => '', 'password_confirmation' => '']))
        ->assertSessionHasErrors(['password' => 'Придумайте пароль']);
    $this->post("/events/{$event->slug}/register", guestRegistration(['password_confirmation' => 'другой']))
        ->assertSessionHasErrors(['password' => 'Пароли не совпадают']);

    expect(User::query()->where('email', 'new.guest@example.com')->exists())->toBeFalse()
        ->and($this->client->calls)->toBe([]);
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

it('gives every response an X-Request-Id and keeps the one that came with the request', function (): void {
    $this->get('/up')->assertHeader('X-Request-Id');

    $this->withHeader('X-Request-Id', 'client-trace-12345678')->get('/up')
        ->assertHeader('X-Request-Id', 'client-trace-12345678');
});
