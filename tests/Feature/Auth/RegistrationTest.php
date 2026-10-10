<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register and get a welcome email', function () {
    $client = new class implements App\Sender\Contracts\SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            $this->calls[] = compact('template', 'to', 'data');

            return ['id' => 'x', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(App\Sender\Contracts\SenderClient::class, $client);

    $response = $this->post('/register', [
        'first_name' => 'Анна',
        'last_name' => 'Иванова',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['template'])->toBe('welcome')
        ->and($client->calls[0]['to'])->toBe('test@example.com')
        ->and($client->calls[0]['data']['first_name'])->toBe('Анна');
});

test('registration completes even if the welcome email fails', function () {
    $this->app->instance(App\Sender\Contracts\SenderClient::class, new class implements App\Sender\Contracts\SenderClient
    {
        public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
        {
            throw new RuntimeException("Шаблон Sender 'welcome' не найден");
        }
    });

    $this->post('/register', [
        'first_name' => 'Пётр', 'last_name' => 'Сидоров', 'email' => 'petr@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
