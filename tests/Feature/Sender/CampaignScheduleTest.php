<?php

use App\Models\User as SiteUser;
use App\Sender\Dns\DnsLookup;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendCampaignJob;
use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\User;
use App\Sender\Services\ApiKeyService;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignSchedule;
use App\Sender\Services\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class]);

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
        'body_html' => '<html><body><p>Здравствуйте, {{ name }}!</p></body></html>', 'sender_address_id' => $sender->id,
    ]);
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
});

function scheduleApi(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

/**
 * @param  array<string, mixed>  $settings
 */
function dripCampaign(int $contacts, array $settings = []): Campaign
{
    $rows = [];
    for ($i = 1; $i <= $contacts; $i++) {
        $rows[] = ['organization_id' => test()->organization->id, 'list_id' => test()->list->id, 'email' => "doctor{$i}@example.com", 'name' => "Врач {$i}", 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        App\Sender\Models\Contact::query()->insert($chunk);
    }

    return test()->organization->campaigns()->create([
        'name' => 'Ноябрь', 'template_id' => test()->template->id, 'list_id' => test()->list->id,
        'status' => Campaign::STATUS_DRAFT, 'send_mode' => Campaign::MODE_DRIP, 'batch_size' => 2, 'batch_interval' => 10, ...$settings,
    ]);
}

function statuses(Campaign $campaign): array
{
    return $campaign->messages()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
}

it('sends a drip campaign in batches with a pause between them and finishes after the last letter', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(5);
    $service = app(CampaignService::class);

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/send"))
        ->assertOk()->assertJsonPath('data.status', 'sending');
    $service->prepare($campaign->fresh());

    expect(statuses($campaign))->toBe(['pending' => 3, 'queued' => 2]);
    Queue::assertPushed(SendMessageJob::class, 2);

    $this->travel(5)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(0);

    $this->travel(5)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(2);

    $this->travel(10)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(1)
        ->and($campaign->fresh()->status)->toBe(Campaign::STATUS_SENDING);

    // все письма ушли: следующий проход завершает рассылку
    $campaign->messages()->update(['status' => Message::STATUS_SENT]);
    $this->travel(10)->minutes();
    $service->tickAll();

    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_SENT)
        ->and($campaign->fresh()->finished_at)->not->toBeNull();
});

it('keeps to the daily limit and the sending window in moscow time', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 19:50', 'Europe/Moscow'));
    $campaign = dripCampaign(10, ['batch_size' => 4, 'batch_interval' => 5, 'daily_limit' => 6, 'window_start' => '09:00', 'window_end' => '20:00', 'window_days' => [1, 2, 3, 4, 5]]);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());

    expect(statuses($campaign)['queued'])->toBe(4);

    $this->travel(5)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(2);

    // 20:00 — окно закрыто
    $this->travel(10)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(0);

    // вторник 09:00: новый день, лимит снова 6
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00', 'Europe/Moscow'));
    expect($service->tick($campaign->fresh()))->toBe(4);

    // суббота не входит в дни отправки
    expect(app(CampaignSchedule::class)->inWindow($campaign->fresh(), CarbonImmutable::parse('2026-10-17 12:00', 'Europe/Moscow')))->toBeFalse();
});

it('starts a scheduled campaign only when its time comes', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00', 'Europe/Moscow'));
    $campaign = dripCampaign(3, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null, 'scheduled_at' => now()->addHours(2)]);
    $service = app(CampaignService::class);

    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());

    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and(statuses($campaign))->toBe(['pending' => 3]);

    $this->travel(119)->minutes();
    $service->tickAll();
    expect(statuses($campaign))->toBe(['pending' => 3]);

    $this->travel(1)->minutes();
    $service->tickAll();
    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_SENDING)
        ->and(statuses($campaign))->toBe(['queued' => 3]);
});

it('pauses a campaign: queued letters go back to wait and nothing is sent until resume', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    // задачи писем выполняются вручную: так письмо «стоит в очереди», пока рассылку ставят на паузу
    Queue::fake();
    $campaign = dripCampaign(3);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());
    $queued = $campaign->messages()->where('status', Message::STATUS_QUEUED)->pluck('id');

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/pause"))
        ->assertOk()->assertJsonPath('data.status', 'paused');

    foreach ($queued as $id) {
        (new SendMessageJob($id))->handle(app(App\Sender\Services\TemplateRenderer::class), app(App\Sender\Transport\Transport::class));
    }

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(statuses($campaign))->toBe(['pending' => 3]);

    $this->travel(30)->minutes();
    $service->tickAll();
    expect(statuses($campaign))->toBe(['pending' => 3]);

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/resume"))
        ->assertOk()->assertJsonPath('data.status', 'sending');
    expect(statuses($campaign))->toBe(['pending' => 1, 'queued' => 2]);
});

it('cancels a campaign so that no remaining letter is sent', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    Queue::fake();
    $campaign = dripCampaign(5);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());
    $queuedId = $campaign->messages()->where('status', Message::STATUS_QUEUED)->value('id');

    $this->withToken($this->token)->deleteJson(scheduleApi("/campaigns/{$campaign->id}"))->assertUnprocessable();

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/cancel"))
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.stats.cancelled', 5);

    (new SendMessageJob($queuedId))->handle(app(App\Sender\Services\TemplateRenderer::class), app(App\Sender\Transport\Transport::class));
    $this->travel(1)->hour();
    $service->tickAll();

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(statuses($campaign))->toBe(['cancelled' => 5]);

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/resume"))->assertUnprocessable();
    $this->withToken($this->token)->deleteJson(scheduleApi("/campaigns/{$campaign->id}"))->assertOk();
});

it('keeps all campaigns within the hourly limit of the server', function (): void {
    Queue::fake();
    config(['sender.hourly_limit' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(10, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null]);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());

    expect(statuses($campaign)['queued'])->toBe(3);

    $this->travel(30)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(0);

    $this->travel(31)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(3);
});

it('prepares a large base in several short jobs', function (): void {
    Queue::fake();
    $campaign = dripCampaign(CampaignService::PREPARE_PER_JOB + 3);
    test()->list->contacts()->update(['check_status' => 'ok', 'checked_at' => now()]);
    app(CampaignService::class)->start($campaign->load(['template', 'list']));

    // адреса уже проверены: та же задача сразу готовит письма
    (new SendCampaignJob($campaign->id))->handle(app(CampaignService::class));

    expect($campaign->messages()->count())->toBe(CampaignService::PREPARE_PER_JOB)
        ->and($campaign->fresh()->prepared_at)->toBeNull();
    Queue::assertPushed(SendCampaignJob::class, fn (SendCampaignJob $job): bool => ! $job->checking && $job->afterContactId > 0);

    $next = Queue::pushed(SendCampaignJob::class)->last();
    $next->handle(app(CampaignService::class));

    expect($campaign->messages()->count())->toBe(CampaignService::PREPARE_PER_JOB + 3)
        ->and($campaign->fresh()->prepared_at)->not->toBeNull()
        ->and($campaign->fresh()->recipients_count)->toBe(CampaignService::PREPARE_PER_JOB + 3);
});

it('estimates when a drip campaign finishes', function (): void {
    $schedule = app(CampaignSchedule::class);
    $start = CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow');

    $campaign = new Campaign(['send_mode' => Campaign::MODE_DRIP, 'batch_size' => 100, 'batch_interval' => 5]);
    expect($schedule->estimateFinish($campaign, 5000, 0, $start)->equalTo($start->addMinutes(49 * 5)))->toBeTrue();

    // 1500 в сутки с 09:00 до 20:00: 1500 в понедельник, по 1500 во вторник и среду, последние 500 в четверг утром
    $campaign->fill(['daily_limit' => 1500, 'window_start' => '09:00', 'window_end' => '20:00']);
    expect($schedule->estimateFinish($campaign, 5000, 0, $start)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'))->toBe('2026-10-15 09:20');

    expect($schedule->estimateFinish(new Campaign(['send_mode' => Campaign::MODE_NOW]), 5000, 0, $start))->toBeNull();
});

it('validates drip settings and drops them when sending at once', function (): void {
    $this->withToken($this->token)->postJson(scheduleApi('/campaigns'), ['name' => 'Ноябрь', 'send_mode' => 'drip'])
        ->assertUnprocessable()->assertJsonValidationErrors(['batch_size', 'batch_interval']);

    $this->withToken($this->token)->postJson(scheduleApi('/campaigns'), [
        'name' => 'Ноябрь', 'send_mode' => 'drip', 'batch_size' => 100, 'batch_interval' => 5, 'window_start' => '20:00', 'window_end' => '09:00', 'window_days' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors(['window_end']);

    $id = $this->withToken($this->token)->postJson(scheduleApi('/campaigns'), [
        'name' => 'Ноябрь', 'send_mode' => 'drip', 'batch_size' => 100, 'batch_interval' => 5, 'daily_limit' => 1500,
        'window_start' => '09:00', 'window_end' => '20:00', 'window_days' => [1, 2, 3, 4, 5],
    ])->assertCreated()->assertJsonPath('data.batch_size', 100)->assertJsonPath('data.window_days', [1, 2, 3, 4, 5])->json('data.id');

    $this->withToken($this->token)->putJson(scheduleApi("/campaigns/{$id}"), ['name' => 'Ноябрь', 'send_mode' => 'now', 'batch_size' => 100])
        ->assertOk()->assertJsonPath('data.send_mode', 'now')->assertJsonPath('data.batch_size', null)->assertJsonPath('data.window_start', null);

    $this->withToken($this->token)->putJson(scheduleApi("/campaigns/{$id}"), ['name' => 'Ноябрь', 'scheduled_at' => now()->subHour()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
});

it('remembers an unsubscribe for bases imported later and clears the consent on the site', function (): void {
    Queue::fake();
    $siteUser = SiteUser::factory()->create(['email' => 'Doctor1@Example.com', 'newsletter_consent' => true]);
    $campaign = dripCampaign(1, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null]);
    app(CampaignService::class)->start($campaign->load(['template', 'list']));
    app(CampaignService::class)->prepare($campaign->fresh());

    $uuid = $campaign->messages()->value('uuid');
    $this->post('/sender/unsubscribe/'.$uuid)->assertOk()->assertSee('Вы отписались');

    expect(Suppression::query()->where('email', 'doctor1@example.com')->value('reason'))->toBe(Suppression::REASON_UNSUBSCRIBE)
        ->and($siteUser->fresh()->newsletter_consent)->toBeFalse();

    // свежая выгрузка в новую базу: отписавшемуся письмо рассылки не уходит
    $fresh = $this->organization->lists()->create(['name' => 'Выгрузка с сайта']);
    $fresh->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'doctor1@example.com']);
    $fresh->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'colleague@example.com']);
    $next = $this->organization->campaigns()->create(['name' => 'Декабрь', 'template_id' => $this->template->id, 'list_id' => $fresh->id, 'status' => Campaign::STATUS_DRAFT]);
    app(CampaignService::class)->start($next->load(['template', 'list']));
    app(CampaignService::class)->prepare($next->fresh());

    expect($next->fresh()->recipients_count)->toBe(1)
        ->and($next->messages()->pluck('to_email')->all())->toBe(['colleague@example.com']);

    // снова включил галочку на сайте — рассылки снова можно отправлять
    $siteUser->fresh()->update(['newsletter_consent' => true]);

    expect(Suppression::query()->where('email', 'doctor1@example.com')->exists())->toBeFalse();
});

it('accepts consent changes through the api', function (): void {
    $key = (new ApiKeyService)->create($this->organization, 'site')['plain'];

    $this->withToken($key)->putJson('/api/sender/v1/subscriptions', ['email' => 'Anna@Example.com', 'subscribed' => false])
        ->assertOk()->assertJsonPath('email', 'anna@example.com');
    expect(Suppression::query()->where('email', 'anna@example.com')->value('reason'))->toBe(Suppression::REASON_UNSUBSCRIBE);

    $this->withToken($key)->putJson('/api/sender/v1/subscriptions', ['email' => 'anna@example.com', 'subscribed' => true])->assertOk();
    expect(Suppression::query()->where('email', 'anna@example.com')->exists())->toBeFalse();

    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'dead@example.com', 'reason' => Suppression::REASON_BOUNCE]);
    $this->withToken($key)->putJson('/api/sender/v1/subscriptions', ['email' => 'dead@example.com', 'subscribed' => true])->assertOk();
    expect(Suppression::query()->where('email', 'dead@example.com')->exists())->toBeTrue();

    $this->withToken($key)->putJson('/api/sender/v1/subscriptions', ['email' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'subscribed']);
});

it('keeps at most a window of letters of one campaign in the queue and tops it up as they go', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(CampaignService::QUEUE_WINDOW + 200, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null]);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());

    expect(statuses($campaign)['queued'])->toBe(CampaignService::QUEUE_WINDOW);

    $this->travel(1)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(0);

    $campaign->messages()->where('status', Message::STATUS_QUEUED)->orderBy('id')->limit(150)->update(['status' => Message::STATUS_SENT]);
    $this->travel(1)->minutes();
    expect($service->tick($campaign->fresh()))->toBe(150);
});

/**
 * Почта есть у example.com, у dead.ru нет почтового сервера, broken.ru не отвечает.
 */
function fakeMailDns(): void
{
    app()->instance(DnsLookup::class, new class implements DnsLookup
    {
        public function txt(string $host): array
        {
            return [];
        }

        public function mx(string $domain): ?array
        {
            return match ($domain) {
                'example.com' => ['mx.example.com'],
                'broken.ru' => null,
                default => [],
            };
        }

        public function ns(string $domain): ?array
        {
            return [];
        }

        public function hasAddress(string $domain): bool
        {
            return false;
        }
    });
}

/**
 * Прогоняет задачи подготовки рассылки, как их выполнил бы воркер очереди.
 */
function runCampaignJobs(Campaign $campaign): void
{
    (new SendCampaignJob($campaign->id))->handle(app(CampaignService::class));

    for ($done = 1; $done < 50; $done++) {
        $jobs = Queue::pushed(SendCampaignJob::class);

        if ($jobs->count() < $done) {
            return;
        }

        $jobs->get($done - 1)->handle(app(CampaignService::class));
    }
}

it('checks unchecked addresses before preparing letters and skips the bad ones', function (): void {
    Queue::fake();
    fakeMailDns();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(2, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null]);
    foreach (['doctor@dead.ru', 'doctor@mailinator.com', 'doctor@broken.ru'] as $email) {
        $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => $email]);
    }

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/send"))
        ->assertOk()->assertJsonPath('data.status', 'sending')->assertJsonPath('data.prepared', false)
        ->assertJsonPath('data.recipients_count', 5);

    runCampaignJobs($campaign);

    // без почтового сервера и одноразовый ящик отсеяны и видны в отчёте с причиной, неответивший DNS не повод не отправить
    expect($campaign->messages()->where('status', '!=', Message::STATUS_BLOCKED)->orderBy('to_email')->pluck('to_email')->all())
        ->toBe(['doctor1@example.com', 'doctor2@example.com', 'doctor@broken.ru'])
        ->and($campaign->messages()->where('status', Message::STATUS_BLOCKED)->orderBy('to_email')->pluck('error', 'to_email')->all())->toBe([
            'doctor@dead.ru' => 'Адрес не прошёл проверку: у домена нет почтового сервера',
            'doctor@mailinator.com' => 'Адрес не прошёл проверку: одноразовый ящик',
        ])
        ->and($campaign->fresh()->rejected_count)->toBe(2)
        ->and($campaign->fresh()->recipients_count)->toBe(5)
        ->and($campaign->fresh()->prepared_at)->not->toBeNull();

    $this->withToken($this->token)->getJson(scheduleApi("/campaigns/{$campaign->id}"))
        ->assertOk()->assertJsonPath('data.rejected_count', 2)->assertJsonPath('data.prepared', true);
});

it('checks a big base in several short steps before preparing letters', function (): void {
    Queue::fake();
    fakeMailDns();
    $campaign = dripCampaign(CheckContactsJob::PER_JOB + 2);
    app(CampaignService::class)->start($campaign->load(['template', 'list']));

    (new SendCampaignJob($campaign->id))->handle(app(CampaignService::class));
    $next = Queue::pushed(SendCampaignJob::class)->last();

    expect($next->checking)->toBeTrue()
        ->and($next->afterContactId)->toBeGreaterThan(0)
        ->and($campaign->messages()->count())->toBe(0)
        ->and($this->list->contacts()->whereNull('checked_at')->count())->toBe(2);

    // остаток проверяется быстро, и письма готовятся в той же задаче
    $next->handle(app(CampaignService::class));

    expect($this->list->contacts()->whereNull('checked_at')->count())->toBe(0)
        ->and($campaign->messages()->count())->toBe(CheckContactsJob::PER_JOB + 2)
        ->and($campaign->fresh()->prepared_at)->not->toBeNull();
});

it('does not send a prepared letter to someone who unsubscribed or was blocked before their batch', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(6, ['batch_size' => 10, 'scheduled_at' => now()->addDay()]);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());
    expect(statuses($campaign))->toBe(['pending' => 6]);

    // пока рассылка ждёт своего времени: отписка по ссылке, жалоба, удаление из базы, плохой адрес, отписка в базе
    app(App\Sender\Services\SubscriptionService::class)->unsubscribe($this->organization, 'doctor1@example.com');
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'doctor2@example.com', 'reason' => Suppression::REASON_COMPLAINT]);
    Contact::query()->where('email', 'doctor3@example.com')->delete();
    Contact::query()->where('email', 'doctor4@example.com')->update(['check_status' => 'no_mx', 'checked_at' => now()]);
    Contact::query()->where('email', 'doctor5@example.com')->update(['unsubscribed_at' => now()]);

    $this->travel(1)->day();
    $service->tickAll();

    expect(statuses($campaign))->toBe(['blocked' => 5, 'queued' => 1])
        ->and($campaign->messages()->where('status', Message::STATUS_QUEUED)->value('to_email'))->toBe('doctor6@example.com')
        ->and($campaign->messages()->where('status', Message::STATUS_BLOCKED)->orderBy('to_email')->pluck('error', 'to_email')->all())->toBe([
            'doctor1@example.com' => 'Получатель отписался от рассылок',
            'doctor2@example.com' => 'Адрес получателя в списке блокировок',
            'doctor3@example.com' => 'Подписчика больше нет в базе рассылки',
            'doctor4@example.com' => 'Адрес не прошёл проверку: у домена нет почтового сервера',
            'doctor5@example.com' => 'Получатель отписался от рассылок',
        ]);
    Queue::assertPushed(SendMessageJob::class, 1);

    $campaign->messages()->where('status', Message::STATUS_QUEUED)->update(['status' => Message::STATUS_SENT]);
    $service->tickAll();
    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_SENT);
});

it('stops letters of a drip campaign for someone who unsubscribed between batches', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(4);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());
    expect(statuses($campaign))->toBe(['pending' => 2, 'queued' => 2]);

    app(App\Sender\Services\SubscriptionService::class)->unsubscribe($this->organization, 'doctor3@example.com');
    $this->travel(10)->minutes();

    expect($service->tick($campaign->fresh()))->toBe(1)
        ->and(statuses($campaign))->toBe(['blocked' => 1, 'queued' => 3]);
});

it('keeps the start time chosen in the browser, which sends it in utc', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:18', 'Europe/Moscow'));
    $campaign = dripCampaign(2, ['send_mode' => Campaign::MODE_NOW, 'batch_size' => null, 'batch_interval' => null]);

    // 18:22 по Москве: браузер присылает 15:22 UTC
    $this->withToken($this->token)->putJson(scheduleApi("/campaigns/{$campaign->id}"), [
        'name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id,
        'send_mode' => Campaign::MODE_NOW, 'scheduled_at' => '2026-10-12T15:22:00.000Z',
    ])->assertOk()->assertJsonPath('data.scheduled_at', '2026-10-12T18:22:00+03:00');

    $this->withToken($this->token)->postJson(scheduleApi("/campaigns/{$campaign->id}/send"))
        ->assertOk()->assertJsonPath('data.status', 'scheduled');
    $service = app(CampaignService::class);
    $service->prepare($campaign->fresh());

    $this->travel(3)->minutes();
    $service->tickAll();
    expect(statuses($campaign))->toBe(['pending' => 2]);

    $this->travel(1)->minutes();
    $service->tickAll();
    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_SENDING)
        ->and(statuses($campaign))->toBe(['queued' => 2]);
});

it('lists the recipients of one campaign with their status', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Moscow'));
    $campaign = dripCampaign(4, ['batch_size' => 2]);
    $other = dripCampaign(0, ['name' => 'Другая']);
    $service = app(CampaignService::class);
    $service->start($campaign->load(['template', 'list']));
    $service->prepare($campaign->fresh());
    $service->start($other->load(['template', 'list']));
    $service->prepare($other->fresh());
    app(App\Sender\Services\SubscriptionService::class)->unsubscribe($this->organization, 'doctor3@example.com');
    $this->travel(10)->minutes();
    $service->tick($campaign->fresh());
    $campaign->messages()->where('status', Message::STATUS_QUEUED)->where('to_email', 'doctor1@example.com')->update(['status' => Message::STATUS_SENT]);

    $list = fn (string $status = ''): array => $this->withToken($this->token)
        ->getJson(scheduleApi("/messages?campaign={$campaign->id}".($status ? "&status={$status}" : '')))
        ->assertOk()->json('data');

    expect(collect($list())->pluck('to')->sort()->values()->all())->toBe(['doctor1@example.com', 'doctor2@example.com', 'doctor3@example.com', 'doctor4@example.com'])
        ->and(collect($list('outgoing'))->pluck('to')->all())->toBe(['doctor1@example.com'])
        ->and(collect($list('waiting'))->pluck('to')->sort()->values()->all())->toBe(['doctor2@example.com', 'doctor4@example.com'])
        ->and($list('missed'))->toHaveCount(1)
        ->and($list('missed')[0])->toMatchArray(['to' => 'doctor3@example.com', 'status' => 'blocked', 'error' => 'Получатель отписался от рассылок']);
});
