<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendSubscriptionConfirmationsJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use App\Sender\Services\SubscriptionConfirmationService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class, SendSubscriptionConfirmationsJob::class]);

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $this->domain = $this->organization->domains()->create([
        'domain' => 'mag-expert.ru',
        'verification_token' => 'token',
        'status' => Domain::STATUS_VERIFIED,
        'dkim_selector' => 'mail',
    ]);
    $sender = $this->organization->senderAddresses()->create([
        'domain_id' => $this->domain->id,
        'email' => 'news@mag-expert.ru',
        'name' => 'Новости MagExpert',
    ]);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'digest',
        'name' => 'Дайджест',
        'subject' => 'Новости для {{ name }}',
        'body_html' => '<html><body><p>Здравствуйте, {{ name }}!</p></body></html>',
        'sender_address_id' => $sender->id,
    ]);

    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
});

function confirmationAdmin(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

function sentMails(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
}

/**
 * Как воркер: снимает блокировку уникальности (её снимает начало обработки) и выполняет задачу.
 */
function runConfirmations(int $listId): void
{
    $job = new SendSubscriptionConfirmationsJob($listId);
    (new UniqueLock(app(Cache::class)))->release($job);
    $job->handle(app(SubscriptionConfirmationService::class));
}

it('imports addresses without consent as awaiting confirmation and queues the letters', function (): void {
    $this->withToken($this->token)->post(confirmationAdmin('/lists/'.$this->list->id.'/import'), [
        'file' => UploadedFile::fake()->createWithContent('base.csv', "email;имя\nanna@example.com;Анна\npetr@example.com;Пётр\n"),
        'consent' => '0',
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.pending', 2);

    $anna = $this->list->contacts()->where('email', 'anna@example.com')->first();

    expect($anna->isPending())->toBeTrue()
        ->and($anna->confirmation_token)->toHaveLength(48)
        ->and($anna->confirmation_sent_at)->toBeNull();

    Queue::assertPushedOn('sender', SendSubscriptionConfirmationsJob::class, fn ($job) => $job->listId === $this->list->id);

    $this->withToken($this->token)->getJson(confirmationAdmin('/lists/'.$this->list->id.'/contacts'))
        ->assertOk()
        ->assertJsonPath('data.0.pending', true)
        ->assertJsonPath('data.0.confirmed_at', null)
        ->assertJsonMissingPath('data.0.confirmation_token');

    $this->withToken($this->token)->getJson(confirmationAdmin('/lists/'.$this->list->id.'/contacts?status=pending'))
        ->assertOk()->assertJsonPath('meta.total', 2);
    $this->withToken($this->token)->getJson(confirmationAdmin('/lists/'.$this->list->id.'/contacts?status=subscribed'))
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('accepts consent as true/false strings and keeps the old behaviour when consent is given or absent', function (): void {
    $import = fn (array $payload) => $this->withToken($this->token)
        ->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), $payload)->assertOk();

    $import(['text' => 'a@example.com'])->assertJsonPath('data.pending', 0);
    $import(['text' => 'b@example.com', 'consent' => true])->assertJsonPath('data.pending', 0);
    $import(['text' => 'c@example.com', 'consent' => 'true'])->assertJsonPath('data.pending', 0);
    $import(['text' => 'd@example.com', 'consent' => '1'])->assertJsonPath('data.pending', 0);
    $import(['text' => 'e@example.com', 'consent' => 'false'])->assertJsonPath('data.pending', 1);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => 'f@example.com', 'consent' => 'maybe'])
        ->assertUnprocessable()->assertJsonValidationErrors('consent');

    expect($this->list->contacts()->deliverable()->pluck('email')->sort()->values()->all())
        ->toBe(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com']);

    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 1);
});

it('sends exactly one confirmation letter per pending contact', function (): void {
    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), [
        'text' => "anna@example.com;Анна\npetr@example.com;Пётр",
        'consent' => false,
    ])->assertOk();

    runConfirmations($this->list->id);
    runConfirmations($this->list->id);

    $mails = sentMails();
    expect($mails)->toHaveCount(2);

    $anna = $this->list->contacts()->where('email', 'anna@example.com')->first();
    $mail = collect($mails)->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'anna@example.com')->getOriginalMessage();
    $url = SubscriptionConfirmationService::url($anna->confirmation_token);

    expect($mail->getSubject())->toBe('Подтвердите подписку на рассылку MagExpert')
        ->and($mail->getFrom()[0]->getAddress())->toBe('news@mag-expert.ru')
        ->and($mail->getHtmlBody())->toContain('Подтвердить подписку')
        ->and($mail->getHtmlBody())->toContain(e($url))
        ->and($mail->getHtmlBody())->not->toContain('/t/o/')
        ->and($mail->getTextBody())->toContain('Подтвердить подписку: '.$url)
        ->and($mail->getHeaders()->has('List-Unsubscribe'))->toBeFalse()
        ->and($anna->confirmation_sent_at)->not->toBeNull();

    $messages = Message::query()->whereNull('campaign_id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages->every(fn (Message $m) => $m->status === Message::STATUS_SENT && ! $m->tracked))->toBeTrue();
});

it('sends the letters in portions and continues later', function (): void {
    $emails = collect(range(1, SubscriptionConfirmationService::PER_RUN + 5))->map(fn (int $i) => "user{$i}@example.com")->implode("\n");

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => $emails, 'consent' => false])->assertOk();

    runConfirmations($this->list->id);

    // первая задача — от загрузки, вторая — продолжение через минуту
    expect(sentMails())->toHaveCount(SubscriptionConfirmationService::PER_RUN);
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class, 2);

    runConfirmations($this->list->id);
    expect(sentMails())->toHaveCount(SubscriptionConfirmationService::PER_RUN + 5);
});

it('respects the hourly limit of the server', function (): void {
    config(['sender.hourly_limit' => 1]);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => "a@example.com\nb@example.com", 'consent' => false])->assertOk();

    runConfirmations($this->list->id);
    runConfirmations($this->list->id);

    expect(sentMails())->toHaveCount(1);
});

it('excludes pending contacts from campaigns and counts', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'old@example.com', 'name' => 'Старый']);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => "new@example.com\nnew2@example.com", 'consent' => false])->assertOk();

    $this->withToken($this->token)->getJson(confirmationAdmin('/lists/'.$this->list->id))
        ->assertOk()
        ->assertJsonPath('data.contacts_count', 3)
        ->assertJsonPath('data.subscribed_count', 1)
        ->assertJsonPath('data.deliverable_count', 1)
        ->assertJsonPath('data.pending_count', 2);

    $this->withToken($this->token)->getJson(confirmationAdmin('/lists'))
        ->assertOk()->assertJsonPath('data.0.pending_count', 2);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/count'), [])
        ->assertOk()->assertJsonPath('data.count', 1);

    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_SENDING]);
    app(CampaignService::class)->prepare($campaign);

    expect(Message::query()->where('campaign_id', $campaign->id)->pluck('to_email')->all())->toBe(['old@example.com']);
});

it('confirms the subscription by the link, idempotently', function (): void {
    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => 'anna@example.com', 'consent' => false])->assertOk();
    runConfirmations($this->list->id);

    $contact = $this->list->contacts()->firstOrFail();
    $path = parse_url(SubscriptionConfirmationService::url($contact->confirmation_token), PHP_URL_PATH);

    // GET только показывает кнопку: почтовый сканер, открывший ссылку, подписку не подтвердит
    $this->get($path)->assertOk()->assertSee('Подтвердить подписку');
    expect($contact->fresh()->isPending())->toBeTrue();

    $this->post($path)->assertOk()->assertSee('Подписка подтверждена');
    $confirmedAt = $contact->fresh()->confirmed_at;
    expect($confirmedAt)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->post($path)->assertOk()->assertSee('Подписка подтверждена');
    $this->get($path)->assertOk()->assertSee('Подписка подтверждена');

    expect($contact->fresh()->confirmed_at->equalTo($confirmedAt))->toBeTrue()
        ->and($this->list->contacts()->deliverable()->count())->toBe(1);

    $this->withToken($this->token)->getJson(confirmationAdmin('/lists/'.$this->list->id))
        ->assertJsonPath('data.pending_count', 0)->assertJsonPath('data.subscribed_count', 1);

    // письмо подтверждения больше не уходит
    runConfirmations($this->list->id);
    expect(sentMails())->toHaveCount(1);
});

it('shows a 404 page for an unknown confirmation link', function (): void {
    $this->get('/sender/confirm-subscription/'.str_repeat('a', 48))->assertNotFound()->assertSee('Ссылка недействительна');
    $this->post('/sender/confirm-subscription/unknown')->assertNotFound()->assertSee('Ссылка недействительна');
});

it('keeps an existing subscriber subscribed when re-imported without consent', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com', 'name' => 'Анна']);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), ['text' => 'anna@example.com;Анна Иванова', 'consent' => false])
        ->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.pending', 0);

    $anna = $this->list->contacts()->firstOrFail();
    expect($anna->isPending())->toBeFalse()
        ->and($anna->name)->toBe('Анна Иванова')
        ->and($this->list->contacts()->deliverable()->count())->toBe(1);

    Queue::assertNotPushed(SendSubscriptionConfirmationsJob::class);
    runConfirmations($this->list->id);
    expect(sentMails())->toHaveCount(0);
});

it('does not send a confirmation letter to unsubscribed or blocked addresses', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'gone@example.com', 'unsubscribed_at' => now()]);
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'bounced@example.com', 'reason' => 'bounce']);
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'left@example.com', 'reason' => Suppression::REASON_UNSUBSCRIBE]);

    $this->withToken($this->token)->postJson(confirmationAdmin('/lists/'.$this->list->id.'/import'), [
        'text' => "gone@example.com\nbounced@example.com\nleft@example.com\nok@example.com",
        'consent' => false,
    ])->assertOk()->assertJsonPath('data.pending', 3);

    runConfirmations($this->list->id);

    $mails = sentMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('ok@example.com')
        ->and(Contact::query()->where('email', 'gone@example.com')->first()->unsubscribed_at)->not->toBeNull()
        ->and(Contact::query()->where('email', 'gone@example.com')->first()->isPending())->toBeFalse();
});
