<?php

use App\Models\User as SiteUser;
use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendCampaignJob;
use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Campaign;
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
    app(CampaignService::class)->start($campaign->load(['template', 'list']));

    (new SendCampaignJob($campaign->id))->handle(app(CampaignService::class));

    expect($campaign->messages()->count())->toBe(CampaignService::PREPARE_PER_JOB)
        ->and($campaign->fresh()->prepared_at)->toBeNull();
    Queue::assertPushed(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->afterContactId > 0);

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
    $next = $this->organization->campaigns()->create(['name' => 'Декабрь', 'template_id' => $this->template->id, 'list_id' => $fresh->id, 'status' => Campaign::STATUS_DRAFT]);
    app(CampaignService::class)->start($next->load(['template', 'list']));
    app(CampaignService::class)->prepare($next->fresh());

    expect($next->messages()->value('status'))->toBe(Message::STATUS_BLOCKED)
        ->and($next->messages()->value('error'))->toContain('отписался');

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
