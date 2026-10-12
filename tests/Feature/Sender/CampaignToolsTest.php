<?php

use App\Models\User as SiteUser;
use App\Sender\Dns\Blacklists;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use App\Sender\Services\HealthMonitor;
use App\Sender\Services\MessageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class]);
    // на машине разработчика может стоять свой Postfix: тесты его не трогают
    config(['sender.alerts.postqueue' => '/nonexistent/postqueue']);

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $domain = $this->organization->domains()->create([
        'domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail',
    ]);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости для {{ name }}',
        'body_html' => '<html><body><p>Здравствуйте, {{ name }} ({{ specialization }})!</p></body></html>', 'sender_address_id' => $sender->id,
    ]);
});

function toolsApi(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

it('builds a base from site users who agreed to the newsletter and keeps it in sync', function (): void {
    $anna = SiteUser::factory()->create(['email' => 'Anna@Example.com', 'first_name' => 'Анна', 'last_name' => 'Иванова', 'specialization' => 'Кардиолог', 'newsletter_consent' => true]);
    SiteUser::factory()->create(['email' => 'petr@example.com', 'newsletter_consent' => true]);
    SiteUser::factory()->create(['email' => 'silent@example.com', 'newsletter_consent' => false]);

    $this->withToken($this->token)->getJson(toolsApi('/list-sources'))
        ->assertOk()->assertJsonPath('data.0.key', 'site');

    $id = $this->withToken($this->token)->postJson(toolsApi('/lists'), ['name' => 'Сайт', 'source' => 'site'])
        ->assertCreated()->assertJsonPath('data.source', 'site')->assertJsonPath('data.contacts_count', 2)->json('data.id');

    $contact = Contact::query()->where('email', 'anna@example.com')->firstOrFail();
    expect($contact->name)->toBe('Анна Иванова')
        ->and($contact->data['specialization'])->toBe('Кардиолог');

    // ручное добавление в синхронизируемую базу запрещено
    $this->withToken($this->token)->postJson(toolsApi("/lists/{$id}/contacts"), ['email' => 'x@example.com'])->assertUnprocessable();
    $this->withToken($this->token)->postJson(toolsApi("/lists/{$id}/import"), ['text' => 'x@example.com'])->assertUnprocessable();

    // Анна выключила галочку, появился новый подписчик
    $anna->update(['newsletter_consent' => false]);
    SiteUser::factory()->create(['email' => 'new@example.com', 'newsletter_consent' => true]);

    $this->withToken($this->token)->postJson(toolsApi("/lists/{$id}/sync"))
        ->assertOk()->assertJsonPath('result', ['added' => 1, 'updated' => 1, 'removed' => 1, 'total' => 2]);

    expect(Contact::query()->where('list_id', $id)->pluck('email')->sort()->values()->all())->toBe(['new@example.com', 'petr@example.com']);

    $this->withToken($this->token)->putJson(toolsApi("/lists/{$id}"), ['name' => 'Сайт', 'source' => 'crm'])->assertUnprocessable()->assertJsonValidationErrors('source');
    $this->withToken($this->token)->postJson(toolsApi('/lists'), ['name' => 'Чужой', 'source' => 'crm'])->assertUnprocessable()->assertJsonValidationErrors('source');
});

it('refreshes a site base right before the campaign starts', function (): void {
    Queue::fake();
    SiteUser::factory()->create(['email' => 'early@example.com', 'newsletter_consent' => true]);
    $list = $this->organization->lists()->create(['name' => 'Сайт', 'source' => 'site']);
    app(App\Sender\Services\SubscriberSources::class)->sync($list);
    SiteUser::factory()->create(['email' => 'late@example.com', 'newsletter_consent' => true]);

    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $list->id, 'status' => Campaign::STATUS_DRAFT]);

    // запуск не ждёт синхронизации: база обновляется в начале фоновой проверки адресов
    $this->mock(App\Sender\Services\EmailChecker::class)->shouldReceive('checkContacts');
    $this->withToken($this->token)->postJson(toolsApi("/campaigns/{$campaign->id}/send"))->assertOk();
    (new App\Sender\Jobs\SendCampaignJob($campaign->id))->handle(app(CampaignService::class));

    expect($campaign->fresh()->recipients_count)->toBe(2)
        ->and($campaign->messages()->pluck('to_email')->sort()->values()->all())->toBe(['early@example.com', 'late@example.com']);
});

it('sends a test of the campaign as a chosen subscriber will see it', function (): void {
    $list = $this->organization->lists()->create(['name' => 'Врачи']);
    $list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'first@example.com', 'name' => 'Первый']);
    $list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'olga@example.com', 'name' => 'Ольга Петрова', 'data' => ['specialization' => 'Невролог']]);
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $list->id, 'status' => Campaign::STATUS_DRAFT]);

    $this->withToken($this->token)->postJson(toolsApi("/campaigns/{$campaign->id}/test"), ['to' => 'me@example.com', 'contact_email' => 'olga@example.com'])
        ->assertCreated();

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($sent->getSubject())->toBe('Тест: Новости для Ольга Петрова')
        ->and($sent->getHtmlBody())->toContain('Здравствуйте, Ольга Петрова (Невролог)!')
        ->and($sent->getHtmlBody())->toContain('отпишитесь от рассылки')
        ->and($sent->getTo()[0]->getAddress())->toBe('me@example.com');

    // тест не попадает в рассылку и не мешает отправить письмо на этот адрес потом
    expect($campaign->messages()->count())->toBe(0);

    $this->withToken($this->token)->postJson(toolsApi("/campaigns/{$campaign->id}/test"), ['to' => 'me@example.com', 'contact_email' => 'nobody@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_email');
});

it('lists campaigns with statistics counted for all of them at once', function (): void {
    $list = $this->organization->lists()->create(['name' => 'Врачи']);
    foreach ([1, 2] as $n) {
        $campaign = $this->organization->campaigns()->create(['name' => "Рассылка {$n}", 'template_id' => $this->template->id, 'list_id' => $list->id, 'status' => Campaign::STATUS_SENT]);
        foreach (range(1, $n + 1) as $i) {
            $this->organization->messages()->create([
                'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'template_id' => $this->template->id, 'to_email' => "d{$i}@example.com",
                'from_email' => 'news@mag-expert.ru', 'subject' => 'S', 'status' => $i === 1 ? Message::STATUS_DELIVERED : Message::STATUS_BOUNCED,
                'opened_at' => $i === 1 ? now() : null,
            ]);
        }
    }
    $this->organization->campaigns()->create(['name' => 'Черновик', 'status' => Campaign::STATUS_DRAFT]);

    $data = $this->withToken($this->token)->getJson(toolsApi('/campaigns'))->assertOk()->json('data');

    expect($data[0]['stats'])->toBeNull()
        ->and($data[1]['stats']['delivered'])->toBe(1)->and($data[1]['stats']['bounced'])->toBe(2)->and($data[1]['stats']['opened'])->toBe(1)
        ->and($data[2]['stats']['bounced'])->toBe(1);
});

it('keeps an organization within its daily limit', function (): void {
    Queue::fake();
    $this->organization->update(['daily_limit' => 3]);
    $messages = app(MessageService::class);

    foreach (range(1, 2) as $i) {
        expect($messages->send($this->organization, $this->template, "u{$i}@example.com")->status)->toBe(Message::STATUS_QUEUED);
    }

    // рассылка получает только остаток лимита
    $list = $this->organization->lists()->create(['name' => 'Врачи']);
    foreach (range(1, 5) as $i) {
        $list->contacts()->create(['organization_id' => $this->organization->id, 'email' => "c{$i}@example.com"]);
    }
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $list->id, 'status' => Campaign::STATUS_DRAFT]);
    app(CampaignService::class)->start($campaign->load(['template', 'list']));
    app(CampaignService::class)->prepare($campaign->fresh());

    expect($campaign->messages()->where('status', Message::STATUS_QUEUED)->count())->toBe(1);

    $blocked = $messages->send($this->organization, $this->template, 'late@example.com');
    expect($blocked->status)->toBe(Message::STATUS_BLOCKED)->and($blocked->error)->toContain('лимит');

    $this->artisan('sender:organization', ['slug' => 'magexpert', '--no-limit' => true])->assertSuccessful();
    expect($this->organization->fresh()->daily_limit)->toBeNull();
});

it('reports problems to telegram once, reminds later and says when they are fixed', function (): void {
    config(['sender.alerts.telegram_token' => 'bot-token', 'sender.alerts.telegram_chat_id' => '42', 'sender.server_ip' => '62.217.181.42']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->mock(Blacklists::class)->shouldReceive('listedIn')->andReturn([]);

    $stuck = $this->organization->messages()->create([
        'uuid' => (string) Str::uuid(), 'template_id' => $this->template->id, 'to_email' => 'u@example.com',
        'from_email' => 'news@mag-expert.ru', 'subject' => 'S', 'status' => Message::STATUS_QUEUED,
    ]);
    $stuck->forceFill(['created_at' => now()->subMinutes(12)])->save();

    $this->artisan('sender:health')->assertSuccessful();
    $this->artisan('sender:health')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Служебные письма не уходят: 1 в очереди, самое старое ждёт 12 мин') && $request['chat_id'] === '42');

    $this->travel(7)->hours();
    $stuck->forceFill(['created_at' => now()->subMinutes(12)])->save();
    $this->artisan('sender:health')->assertSuccessful();
    Http::assertSentCount(2);

    $stuck->update(['status' => Message::STATUS_SENT]);
    $this->artisan('sender:health')->assertSuccessful();
    Http::assertSent(fn ($request): bool => str_starts_with($request['text'], '✅'));
    Http::assertSentCount(3);
});

it('finds a high bounce rate, a stopped scheduler and a blacklisted ip', function (): void {
    config(['sender.server_ip' => '62.217.181.42']);
    $this->mock(Blacklists::class)->shouldReceive('listedIn')->andReturn(['bl.spamcop.net']);

    $message = $this->organization->messages()->create([
        'uuid' => (string) Str::uuid(), 'template_id' => $this->template->id, 'to_email' => 'u@example.com',
        'from_email' => 'news@mag-expert.ru', 'subject' => 'S', 'status' => Message::STATUS_BOUNCED,
    ]);
    foreach (range(1, 60) as $i) {
        $message->events()->create(['type' => $i <= 10 ? MessageEvent::TYPE_BOUNCED : MessageEvent::TYPE_DELIVERED]);
    }

    $this->organization->campaigns()->create(['name' => 'Идёт', 'status' => Campaign::STATUS_SENDING, 'prepared_at' => now()]);
    Cache::put(HealthMonitor::TICK_KEY, now()->subMinutes(20));

    $problems = app(HealthMonitor::class)->problems();

    expect(array_keys($problems))->toBe(['scheduler', 'bounces', 'blacklist'])
        ->and($problems['bounces'])->toContain('16.7%')
        ->and($problems['blacklist'])->toContain('bl.spamcop.net');
});

it('treats a refused dnsbl query as not listed', function (): void {
    $lists = new class extends Blacklists
    {
        protected function resolve(string $host): array
        {
            return match (true) {
                str_ends_with($host, 'zen.spamhaus.org') => ['127.255.255.254'],
                str_ends_with($host, 'bl.spamcop.net') => ['127.0.0.2'],
                default => [],
            };
        }
    };

    expect($lists->listedIn('62.217.181.42', ['zen.spamhaus.org', 'bl.spamcop.net', 'psbl.surriel.com']))->toBe(['bl.spamcop.net']);
});

it('runs the queue check only for pending service letters of the transactional queue', function (): void {
    Queue::fake();
    app(MessageService::class)->send($this->organization, $this->template, 'u@example.com');

    Queue::assertPushedOn('sender-transactional', SendMessageJob::class);
    expect(app(HealthMonitor::class)->problems())->toBe([]);
});
