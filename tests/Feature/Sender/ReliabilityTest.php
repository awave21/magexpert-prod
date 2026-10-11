<?php

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Services\TemplateRenderer;
use App\Sender\Transport\Transport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $this->template = $this->organization->templates()->create(['slug' => 'reset', 'name' => 'Сброс', 'subject' => 'Сброс пароля', 'body_html' => '<p>Ссылка</p>']);
});

function reliabilityMessage(string $status, array $attributes = []): Message
{
    return test()->organization->messages()->create([
        'uuid' => (string) Str::uuid(), 'template_id' => test()->template->id, 'to_email' => 'u'.Str::random(5).'@example.com',
        'from_email' => 'noreply@mag-expert.ru', 'subject' => 'Сброс пароля', 'status' => $status, ...$attributes,
    ]);
}

it('sends a letter once even when its job runs twice', function (): void {
    $message = reliabilityMessage(Message::STATUS_QUEUED);
    $job = fn () => (new SendMessageJob($message->id))->handle(app(TemplateRenderer::class), app(Transport::class));

    $job();
    $job();

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($message->fresh()->attempts)->toBe(1);
});

it('puts a letter back in the queue when postfix refuses it', function (): void {
    $message = reliabilityMessage(Message::STATUS_QUEUED);
    $this->mock(Transport::class)->shouldReceive('send')->andThrow(new RuntimeException('Connection refused'));

    expect(fn () => (new SendMessageJob($message->id))->handle(app(TemplateRenderer::class), app(Transport::class)))
        ->toThrow(RuntimeException::class);

    expect($message->fresh()->status)->toBe(Message::STATUS_QUEUED)
        ->and($message->fresh()->error)->toBe('Connection refused');
});

it('recovers letters stuck in sending after a worker crash', function (): void {
    Queue::fake();
    $log = tempnam(sys_get_temp_dir(), 'maillog');
    config(['sender.mail_log' => $log]);

    $accepted = reliabilityMessage(Message::STATUS_SENDING, ['smtp_queue_id' => '9AA1']);
    $lost = reliabilityMessage(Message::STATUS_SENDING);
    $fresh = reliabilityMessage(Message::STATUS_SENDING);
    Message::query()->whereKey([$accepted->id, $lost->id])->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('sender:recover')->expectsOutputToContain('Отправлены ранее: 1, поставлены заново: 1, с ошибкой: 0')->assertSuccessful();

    expect($accepted->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($lost->fresh()->status)->toBe(Message::STATUS_QUEUED)
        ->and($fresh->fresh()->status)->toBe(Message::STATUS_SENDING);
    Queue::assertPushedOn('sender-transactional', SendMessageJob::class, fn (SendMessageJob $job): bool => $job->messageId === $lost->id);

    // без журнала Postfix не угадываем: помечаем ошибкой, чтобы не отправить дважды
    unlink($log);
    $unknown = reliabilityMessage(Message::STATUS_SENDING);
    Message::query()->whereKey($unknown->id)->update(['updated_at' => now()->subMinutes(20)]);
    $this->artisan('sender:recover')->assertSuccessful();

    expect($unknown->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($unknown->fresh()->error)->toContain($unknown->uuid);
});

it('reads the tail of the rotated log before the new file', function (): void {
    $sent = reliabilityMessage(Message::STATUS_SENT, ['to_email' => 'anna@mail.ru']);
    $late = reliabilityMessage(Message::STATUS_SENT, ['to_email' => 'petr@mail.ru']);
    $host = config('sender.mail_host');
    $dir = sys_get_temp_dir().'/maillog-'.Str::random(6);
    mkdir($dir);
    $file = $dir.'/mail.log';

    file_put_contents($file, "Oct 10 postfix/cleanup[1]: 9AA1: message-id=<{$sent->uuid}@{$host}>\nOct 10 postfix/cleanup[1]: 9BB2: message-id=<{$late->uuid}@{$host}>\n");
    $this->artisan('sender:mail-log', ['file' => $file])->assertSuccessful();

    // строка дописана после последнего чтения, потом logrotate переименовал файл
    file_put_contents($file, "Oct 10 postfix/smtp[2]: 9AA1: to=<anna@mail.ru>, relay=x, dsn=2.0.0, status=sent (250 OK)\n", FILE_APPEND);
    rename($file, $file.'.1');
    file_put_contents($file, "Oct 11 postfix/smtp[3]: 9BB2: to=<petr@mail.ru>, relay=x, dsn=2.0.0, status=sent (250 OK)\n");

    $this->artisan('sender:mail-log', ['file' => $file])->expectsOutputToContain('Прочитано строк: 2, о наших письмах: 2')->assertSuccessful();

    expect($sent->fresh()->status)->toBe(Message::STATUS_DELIVERED)
        ->and($late->fresh()->status)->toBe(Message::STATUS_DELIVERED);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('puts back letters whose queue job was lost, without sending them twice', function (): void {
    Queue::fake();
    $lost = reliabilityMessage(Message::STATUS_QUEUED);
    $recent = reliabilityMessage(Message::STATUS_QUEUED);
    Message::query()->whereKey($lost->id)->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('sender:recover')->expectsOutputToContain('потерянные задачи восстановлены: 1')->assertSuccessful();

    Queue::assertPushed(SendMessageJob::class, 1);
    Queue::assertPushedOn('sender-transactional', SendMessageJob::class, fn (SendMessageJob $job): bool => $job->messageId === $lost->id);

    // и старая, и новая задача сработали — письмо ушло один раз
    $job = fn () => (new SendMessageJob($lost->id))->handle(app(TemplateRenderer::class), app(Transport::class));
    $job();
    $job();
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and($recent->fresh()->status)->toBe(Message::STATUS_QUEUED);
});

it('returns a letter to the queue when its template fails to render', function (): void {
    $message = reliabilityMessage(Message::STATUS_QUEUED);
    $this->mock(TemplateRenderer::class)->shouldReceive('render')->andThrow(new RuntimeException('Сломанный шаблон'));

    expect(fn () => (new SendMessageJob($message->id))->handle(app(TemplateRenderer::class), app(Transport::class)))
        ->toThrow(RuntimeException::class);

    expect($message->fresh()->status)->toBe(Message::STATUS_QUEUED)
        ->and($message->fresh()->error)->toBe('Сломанный шаблон');

    // после последней попытки письмо становится ошибкой, а не висит «отправляется»
    (new SendMessageJob($message->id))->failed(new RuntimeException('Сломанный шаблон'));
    expect($message->fresh()->status)->toBe(Message::STATUS_FAILED);
});
