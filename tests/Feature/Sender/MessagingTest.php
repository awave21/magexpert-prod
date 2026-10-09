<?php

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Services\ApiKeyService;
use App\Sender\Services\MessageService;
use App\Sender\Services\TemplateRenderer;
use App\Sender\Transport\Transport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);

    $this->domain = $this->organization->domains()->create([
        'domain' => 'mag-expert.ru',
        'verification_token' => 'token',
        'status' => Domain::STATUS_VERIFIED,
        'dkim_selector' => 'mail',
    ]);

    $this->template = $this->organization->templates()->create([
        'slug' => 'event-registration',
        'name' => 'Регистрация на мероприятие',
        'subject' => 'Вы записаны: {{ event }}',
        'body_html' => '<p>Здравствуйте, {{ name }}!</p>',
        'body_text' => 'Здравствуйте, {{ name }}!',
    ]);
});

it('creates an api key and stores only its hash', function (): void {
    $result = (new ApiKeyService)->create($this->organization, 'MagExpert app');

    expect($result['plain'])->toStartWith('mxs_')
        ->and($result['key']->key_hash)->not->toBe($result['plain'])
        ->and($result['key']->key_hash)->toHaveLength(64);
});

it('authenticates a valid key and rejects a revoked one', function (): void {
    $service = new ApiKeyService;
    $result = $service->create($this->organization, 'MagExpert app');

    expect($service->authenticate($result['plain'])?->id)->toBe($result['key']->id)
        ->and($result['key']->fresh()->last_used_at)->not->toBeNull();

    $service->revoke($result['key']);

    expect($service->authenticate($result['plain']))->toBeNull()
        ->and($service->authenticate('mxs_unknown'))->toBeNull();
});

it('renders template variables and escapes html', function (): void {
    $content = (new TemplateRenderer)->render($this->template, ['name' => '<b>Анна</b>', 'event' => 'Вебинар']);

    expect($content['subject'])->toBe('Вы записаны: Вебинар')
        ->and($content['html'])->toBe('<p>Здравствуйте, &lt;b&gt;Анна&lt;/b&gt;!</p>')
        ->and($content['text'])->toBe('Здравствуйте, <b>Анна</b>!');
});

it('queues a message from a verified domain', function (): void {
    Queue::fake();

    $message = app(MessageService::class)->send(
        $this->organization, $this->template, 'User@Example.com', 'noreply@mag-expert.ru', 'MagExpert',
        ['name' => 'Анна', 'event' => 'Вебинар'],
    );

    expect($message->status)->toBe(Message::STATUS_QUEUED)
        ->and($message->to_email)->toBe('user@example.com')
        ->and($message->subject)->toBe('Вы записаны: Вебинар');

    Queue::assertPushedOn('sender', SendMessageJob::class);
});

it('blocks a message from an unverified domain', function (): void {
    Queue::fake();
    $this->domain->update(['status' => Domain::STATUS_PENDING]);

    $message = app(MessageService::class)->send(
        $this->organization, $this->template, 'user@example.com', 'noreply@mag-expert.ru',
    );

    expect($message->status)->toBe(Message::STATUS_BLOCKED)
        ->and($message->error)->toContain('не подтверждён');

    Queue::assertNothingPushed();
});

it('blocks a message from an unknown domain', function (): void {
    Queue::fake();

    $message = app(MessageService::class)->send(
        $this->organization, $this->template, 'user@example.com', 'noreply@other.ru',
    );

    expect($message->status)->toBe(Message::STATUS_BLOCKED)
        ->and($message->domain_id)->toBeNull();
});

it('blocks a message to a suppressed address', function (): void {
    Queue::fake();
    Suppression::create([
        'organization_id' => $this->organization->id,
        'email' => 'user@example.com',
        'reason' => Suppression::REASON_UNSUBSCRIBE,
    ]);

    $message = app(MessageService::class)->send(
        $this->organization, $this->template, 'user@example.com', 'noreply@mag-expert.ru',
    );

    expect($message->status)->toBe(Message::STATUS_BLOCKED)
        ->and($message->error)->toContain('блокировок');

    Queue::assertNothingPushed();
});

it('sends a queued message and marks it as sent', function (): void {
    $message = $this->organization->messages()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'domain_id' => $this->domain->id,
        'template_id' => $this->template->id,
        'to_email' => 'user@example.com',
        'from_email' => 'noreply@mag-expert.ru',
        'subject' => 'Тема',
        'data' => ['name' => 'Анна', 'event' => 'Вебинар'],
    ]);

    SendMessageJob::dispatchSync($message->id);

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getHtmlBody())->toBe('<p>Здравствуйте, Анна!</p>')
        ->and($sent->first()->getOriginalMessage()->getTextBody())->toBe('Здравствуйте, Анна!')
        ->and($sent->first()->getOriginalMessage()->getSubject())->toBe('Вы записаны: Вебинар')
        ->and($sent->first()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('noreply@mag-expert.ru')
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->sent_at)->not->toBeNull();
});

it('records the error when the transport fails', function (): void {
    $this->app->bind(Transport::class, fn () => new class implements Transport
    {
        public function send(Message $message, array $content): void
        {
            throw new RuntimeException('SMTP недоступен');
        }
    });

    $message = $this->organization->messages()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'template_id' => $this->template->id,
        'to_email' => 'user@example.com',
        'from_email' => 'noreply@mag-expert.ru',
        'subject' => 'Тема',
    ]);

    $job = new SendMessageJob($message->id);

    expect(fn () => $job->handle(new TemplateRenderer, app(Transport::class)))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('SMTP недоступен'));

    expect($message->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($message->fresh()->error)->toBe('SMTP недоступен');
});

it('renders conditional blocks', function (): void {
    $template = $this->organization->templates()->create([
        'slug' => 'conditional',
        'name' => 'Условия',
        'subject' => 'Тема',
        'body_html' => 'Привет{{#if name}}, {{ name }}{{/if}}!{{#if password}} Пароль: {{ password }}{{/if}}',
    ]);

    $renderer = new TemplateRenderer;

    expect($renderer->render($template, ['name' => 'Анна', 'password' => 'pw'])['html'])->toBe('Привет, Анна! Пароль: pw')
        ->and($renderer->render($template, [])['html'])->toBe('Привет!')
        ->and($renderer->render($template, ['name' => '', 'password' => false])['html'])->toBe('Привет!');
});
