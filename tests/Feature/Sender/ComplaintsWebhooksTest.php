<?php

use App\Models\User as SiteUser;
use App\Sender\Contracts\FeedbackMailbox;
use App\Sender\Jobs\SendWebhookJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\Webhook;
use App\Sender\Services\CampaignService;
use App\Sender\Services\ComplaintService;
use App\Sender\Services\MailLogService;
use App\Sender\Services\Warmup;
use App\Sender\Services\WebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    // тестовые адреса вебхуков не резолвятся: считаем их публичными
    $this->app->instance(App\Sender\Support\PublicAddress::class, new class extends App\Sender\Support\PublicAddress
    {
        protected function addresses(string $host): array
        {
            return ['hooks.example.com' => ['93.184.216.34'], 'internal.example.com' => ['10.0.0.5']][$host] ?? [];
        }
    });

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();
    $this->template = $this->organization->templates()->create([
        'slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости', 'body_html' => '<html><body><p>Текст</p></body></html>', 'sender_address_id' => $sender->id,
    ]);
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
});

function campaignMessage(string $email = 'anna@mail.ru'): Message
{
    $contact = test()->list->contacts()->create(['organization_id' => test()->organization->id, 'email' => $email]);

    return test()->organization->messages()->create([
        'uuid' => (string) Str::uuid(), 'template_id' => test()->template->id, 'to_email' => $contact->email,
        'campaign_id' => test()->organization->campaigns()->create(['name' => 'Ноябрь', 'status' => Campaign::STATUS_SENT])->id,
        'from_email' => 'news@mag-expert.ru', 'subject' => 'Новости', 'status' => Message::STATUS_DELIVERED,
    ]);
}

function arfReport(Message $message, bool $base64 = false): string
{
    $original = "Received: from mail.mag-expert.ru\r\nMessage-ID: <{$message->uuid}@".config('sender.mail_host').">\r\nX-Sender-Id: {$message->uuid}\r\nFrom: news@mag-expert.ru\r\nTo: {$message->to_email}\r\nSubject: Новости\r\n\r\nТекст письма\r\n";
    $part = $base64
        ? "Content-Type: message/rfc822\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($original))
        : "Content-Type: message/rfc822\r\n\r\n".$original;

    return "From: fbl@corp.mail.ru\r\nTo: fbl@mag-expert.ru\r\nMessage-ID: <report-1@corp.mail.ru>\r\nSubject: complaint\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/report; report-type=feedback-report;\r\n\tboundary=\"b1\"\r\n\r\n"
        ."--b1\r\nContent-Type: text/plain\r\n\r\nThis is an abuse report\r\n"
        ."--b1\r\nContent-Type: message/feedback-report\r\n\r\nFeedback-Type: abuse\r\nUser-Agent: Mail.ru FBL\r\nVersion: 1\r\n"
        ."--b1\r\n{$part}\r\n--b1--\r\n";
}

it('puts the sender id and feedback id headers into every letter', function (): void {
    app(App\Sender\Services\MessageService::class)->send($this->organization, $this->template, 'u@example.com');
    $headers = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHeaders();
    $message = Message::query()->latest('id')->first();

    expect($headers->get('X-Sender-Id')->getBodyAsString())->toBe($message->uuid)
        ->and($headers->get('Feedback-ID')->getBodyAsString())->toBe("service:org{$this->organization->id}:transactional:sender");
});

it('handles a spam complaint: blocks the address, unsubscribes it on the site and counts it in the campaign', function (bool $base64): void {
    Queue::fake();
    $siteUser = SiteUser::factory()->create(['email' => 'anna@mail.ru', 'newsletter_consent' => true]);
    $message = campaignMessage();

    $found = app(ComplaintService::class)->handle(arfReport($message, $base64));
    app(ComplaintService::class)->handle(arfReport($message, $base64));

    expect($found?->id)->toBe($message->id)
        ->and(Suppression::query()->where('email', 'anna@mail.ru')->value('reason'))->toBe(Suppression::REASON_COMPLAINT)
        ->and(Contact::query()->where('email', 'anna@mail.ru')->value('unsubscribed_at'))->not->toBeNull()
        ->and($message->events()->where('type', MessageEvent::TYPE_COMPLAINT)->count())->toBe(1)
        ->and($siteUser->fresh()->newsletter_consent)->toBeFalse()
        ->and(app(CampaignService::class)->stats($message->campaign)['complained'])->toBe(1);
})->with(['plain' => false, 'base64' => true]);

it('ignores reports about letters that are not ours', function (): void {
    $report = "From: fbl@corp.mail.ru\r\nMessage-ID: <x@other.ru>\r\nContent-Type: text/plain\r\n\r\nMessage-ID: <".Str::uuid().'@other.ru>';

    expect(app(ComplaintService::class)->handle($report))->toBeNull()
        ->and(Suppression::query()->count())->toBe(0);
});

it('fetches complaint reports from the fbl mailbox and marks them read', function (): void {
    Queue::fake();
    config(['sender.fbl.host' => 'imap.mail.ru']);
    $message = campaignMessage();

    $mailbox = new class([7 => arfReport($message), 8 => "Subject: hello\r\n\r\nне отчёт"]) implements FeedbackMailbox
    {
        public array $seen = [];

        public function __construct(private array $messages) {}

        public function unseen(int $limit): array
        {
            return $this->messages;
        }

        public function markSeen(int $uid): void
        {
            $this->seen[] = $uid;
        }

        public function close(): void {}
    };
    $this->app->instance(FeedbackMailbox::class, $mailbox);

    $this->artisan('sender:complaints', ['--fetch' => true])->expectsOutputToContain('Отчётов: 2, жалоб на наши письма: 1')->assertSuccessful();

    expect($mailbox->seen)->toBe([7, 8])
        ->and(Suppression::query()->where('email', 'anna@mail.ru')->value('reason'))->toBe(Suppression::REASON_COMPLAINT);
});

it('reads a single complaint report from a file', function (): void {
    Queue::fake();
    $message = campaignMessage();
    $file = tempnam(sys_get_temp_dir(), 'arf');
    file_put_contents($file, arfReport($message));

    $this->artisan('sender:complaints', ['file' => $file])->expectsOutputToContain($message->uuid)->assertSuccessful();

    unlink($file);
});

it('posts signed events to webhooks that want them', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $all = $this->organization->webhooks()->create(['url' => 'https://hooks.example.com/all', 'secret' => 'secret-1']);
    $this->organization->webhooks()->create(['url' => 'https://hooks.example.com/clicks', 'secret' => 'secret-2', 'events' => [Webhook::EVENT_CLICKED]]);
    $message = campaignMessage();
    $message->update(['status' => Message::STATUS_SENT, 'smtp_queue_id' => '9AA1']);

    app(MailLogService::class)->handle('Oct 10 postfix/smtp[2]: 9AA1: to=<anna@mail.ru>, relay=x, dsn=2.0.0, status=sent (250 OK)');

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($message): bool {
        $body = $request->body();

        return $request->url() === 'https://hooks.example.com/all'
            && $request->header('X-Sender-Event')[0] === 'message.delivered'
            && $request->header('X-Sender-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 'secret-1')
            && $request['message']['id'] === $message->uuid;
    });
    expect($all->fresh()->last_status)->toBe(200);
});

it('retries a webhook that fails and records the error', function (): void {
    Http::fake(['*' => Http::response('down', 503)]);
    $webhook = $this->organization->webhooks()->create(['url' => 'https://hooks.example.com/all', 'secret' => 's']);

    expect(fn () => (new SendWebhookJob($webhook->id, ['event' => 'test']))->handle(app(WebhookService::class)))->toThrow(RuntimeException::class);

    expect($webhook->fresh()->last_status)->toBe(503)
        ->and($webhook->fresh()->last_error)->toBe('Ответ 503');
});

it('limits campaign letters of all organizations by the ip warm-up schedule', function (): void {
    Queue::fake();
    config(['sender.warmup.start' => '2026-10-12', 'sender.warmup.schedule' => [2, 4]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));

    foreach (range(1, 6) as $i) {
        $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => "d{$i}@example.com"]);
    }
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);
    app(CampaignService::class)->start($campaign->load(['template', 'list']));
    app(CampaignService::class)->prepare($campaign->fresh());

    expect($campaign->messages()->where('status', Message::STATUS_QUEUED)->count())->toBe(2)
        ->and(app(Warmup::class)->status())->toBe(['day' => 1, 'days' => 2, 'limit' => 2, 'sent' => 2, 'remaining' => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00', 'Europe/Moscow'));
    expect(app(CampaignService::class)->tick($campaign->fresh()))->toBe(4);

    // график закончился — лимита нет
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Moscow'));
    expect(app(Warmup::class)->limitToday())->toBeNull();
    $this->artisan('sender:warmup')->expectsOutputToContain('лимита нет')->assertSuccessful();
});

it('refuses webhooks that point into the internal network', function (): void {
    App\Sender\Models\User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $token = (new App\Sender\Services\AuthService)->login('admin@example.com', 'secret-pass')['token'];

    foreach (['https://127.0.0.1/hook', 'https://[::1]/hook', 'https://10.0.0.5/hook', 'https://169.254.169.254/latest', 'https://internal.example.com/hook', 'https://nowhere.example.com/hook'] as $url) {
        $this->withToken($token)->postJson('/api/sender/v1/admin/webhooks', ['url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    $this->withToken($token)->postJson('/api/sender/v1/admin/webhooks', ['url' => 'https://hooks.example.com/sender'])->assertCreated();

    // имя позже переключили на внутренний адрес: отправка не идёт
    Http::fake();
    $webhook = $this->organization->webhooks()->create(['url' => 'https://internal.example.com/hook', 'secret' => 's']);
    (new SendWebhookJob($webhook->id, ['event' => 'test']))->handle(app(WebhookService::class));

    Http::assertNothingSent();
    expect($webhook->fresh()->last_error)->toContain('внутреннюю сеть');
});
