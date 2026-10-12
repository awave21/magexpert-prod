<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendCampaignJob;
use App\Sender\Jobs\SendMessageJob;
use App\Sender\Jobs\SendSubscriptionConfirmationsJob;
use App\Sender\Mcp\McpTokens;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\ContactList;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake();

    $this->organization = Organization::create(['name' => 'Ромашка', 'slug' => 'romashka']);
    $domain = $this->organization->domains()->create(['domain' => 'romashka.test', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@romashka.test', 'name' => 'Ромашка']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости для {{ first_name }}',
        'body_html' => '<p>Здравствуйте, {{ first_name }}!</p><a href="{{ unsubscribe_url }}">Отписаться</a>',
        'sender_address_id' => $sender->id,
    ]);

    $this->list = $this->organization->lists()->create(['name' => 'Клиенты']);
    $this->list->contacts()->createMany([
        ['organization_id' => $this->organization->id, 'email' => 'anna@example.com', 'name' => 'Анна', 'data' => ['city' => 'Москва'], 'last_opened_at' => now()->subDays(3)],
        ['organization_id' => $this->organization->id, 'email' => 'boris@example.com', 'name' => 'Борис', 'data' => ['city' => 'Казань']],
        ['organization_id' => $this->organization->id, 'email' => 'vera@example.com', 'name' => 'Вера', 'data' => ['city' => 'Москва'], 'unsubscribed_at' => now()],
    ]);

    $this->editor = dataUser('editor');
    $this->token = app(McpTokens::class)->issue($this->editor, 'Ключ')['plain'];
});

function dataUser(string $role, ?Organization $organization = null): User
{
    $organization ??= test()->organization;

    return User::create([
        'organization_id' => $organization->id, 'name' => 'Пользователь '.$role, 'email' => $role.'-'.$organization->slug.'@example.com',
        'password' => 'secret-pass', 'role' => $role,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function dataTool(string $token, string $name, array $arguments = []): array
{
    return test()->withToken($token)
        ->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]])
        ->assertOk()
        ->json('result');
}

function auditActions(): array
{
    return AuditLog::query()->orderBy('id')->pluck('action')->all();
}

it('lists, creates and renames subscriber lists', function (): void {
    $lists = dataTool($this->token, 'list_lists')['structuredContent']['lists'];
    expect($lists)->toHaveCount(1)
        ->and($lists[0])->toMatchArray(['name' => 'Клиенты', 'synced' => false, 'contacts_count' => 3, 'subscribed_count' => 2, 'deliverable_count' => 2]);

    $created = dataTool($this->token, 'create_list', ['name' => ' Партнёры ', 'require_confirmation' => true]);
    expect($created['isError'])->toBeFalse()
        ->and($created['structuredContent']['list'])->toMatchArray(['name' => 'Партнёры', 'require_confirmation' => true, 'contacts_count' => 0]);

    expect(dataTool($this->token, 'create_list', ['name' => 'клиенты'])['content'][0]['text'])->toContain('уже есть');

    $renamed = dataTool($this->token, 'update_list', ['list' => 'партнёры', 'name' => 'Партнёры 2026', 'description' => 'Дилеры']);
    expect($renamed['structuredContent']['list'])->toMatchArray(['name' => 'Партнёры 2026', 'description' => 'Дилеры']);

    expect(dataTool($this->token, 'update_list', ['list' => $renamed['structuredContent']['list']['id'], 'name' => 'Клиенты'])['content'][0]['text'])->toContain('уже есть')
        ->and(dataTool($this->token, 'update_list', ['list' => 'Клиенты'])['content'][0]['text'])->toContain('Нечего менять')
        ->and(dataTool($this->token, 'update_list', ['list' => 'Нет такой', 'name' => 'X'])['content'][0]['text'])->toContain('не найдена');

    expect(auditActions())->toBe(['mcp.lists.store', 'mcp.lists.update']);
});

it('adds, updates and unsubscribes contacts like the open API', function (): void {
    $result = dataTool($this->token, 'upsert_contacts', ['list' => 'Клиенты', 'contacts' => [
        ['email' => ' New@Example.com ', 'name' => 'Новый', 'data' => ['city' => 'Сочи']],
        ['email' => 'anna@example.com', 'data' => ['plan' => 'pro', 'city' => null]],
        ['email' => 'boris@example.com', 'subscribed' => false],
        ['email' => 'vera@example.com', 'subscribed' => true],
        ['email' => 'не адрес'],
        ['email' => 'bad-key@example.com', 'data' => ['Bad Key' => 'x']],
    ]])['structuredContent'];

    expect($result)->toMatchArray(['created' => 1, 'updated' => 3, 'subscribed' => 3, 'unsubscribed' => 1])
        ->and(collect($result['invalid'])->pluck('index')->all())->toBe([4, 5]);

    $contacts = $this->list->contacts()->get()->keyBy('email');
    expect($contacts['new@example.com']->name)->toBe('Новый')
        ->and($contacts['anna@example.com']->data)->toBe(['plan' => 'pro'])
        ->and($contacts['boris@example.com']->unsubscribed_at)->not->toBeNull()
        ->and($contacts['vera@example.com']->unsubscribed_at)->toBeNull();
    Queue::assertPushed(CheckContactsJob::class);

    $page = dataTool($this->token, 'list_contacts', ['list' => $this->list->id, 'status' => 'subscribed', 'limit' => 2])['structuredContent'];
    expect($page['total'])->toBe(3)
        ->and($page['returned'])->toBe(2)
        ->and($page['contacts'][0]['email'])->toBe('new@example.com');

    $found = dataTool($this->token, 'list_contacts', ['list' => 'Клиенты', 'query' => 'бор'])['structuredContent'];
    expect(collect($found['contacts'])->pluck('email')->all())->toBe(['boris@example.com'])
        ->and($found['contacts'][0]['status'])->toBe('unsubscribed');

    expect(AuditLog::query()->where('action', 'mcp.contacts.upsert')->value('subject'))->toBe('Клиенты: 4');
});

it('asks new contacts of a confirmation list to confirm and refuses synced lists', function (): void {
    $confirmed = $this->organization->lists()->create(['name' => 'С подтверждением', 'require_confirmation' => true]);
    $result = dataTool($this->token, 'upsert_contacts', ['list' => $confirmed->id, 'contacts' => [['email' => 'x@example.com']]])['structuredContent'];

    expect($result['pending'])->toBe(1)
        ->and($confirmed->contacts()->first()->isPending())->toBeTrue();
    Queue::assertPushed(SendSubscriptionConfirmationsJob::class);

    $synced = ContactList::query()->create(['organization_id' => $this->organization->id, 'name' => 'Пользователи сайта', 'source' => 'site']);
    expect(collect(dataTool($this->token, 'list_lists')['structuredContent']['lists'])->firstWhere('name', 'Пользователи сайта')['synced'])->toBeTrue();

    $refused = dataTool($this->token, 'upsert_contacts', ['list' => $synced->id, 'contacts' => [['email' => 'y@example.com']]]);
    expect($refused['isError'])->toBeTrue()
        ->and($refused['content'][0]['text'])->toContain('обновляется из источника')
        ->and($synced->contacts()->count())->toBe(0);
});

it('describes fields and counts segments', function (): void {
    $fields = dataTool($this->token, 'list_fields', ['list' => 'Клиенты'])['structuredContent']['fields'];
    expect($fields[0]['key'])->toBe('city')
        ->and($fields[0]['values'][0])->toBe(['value' => 'Москва', 'count' => 2]);

    expect(dataTool($this->token, 'count_segment', ['list' => 'Клиенты'])['structuredContent']['count'])->toBe(2)
        ->and(dataTool($this->token, 'count_segment', ['list' => 'Клиенты', 'segment' => ['field' => 'city', 'values' => ['Москва']]])['structuredContent']['count'])->toBe(1)
        ->and(dataTool($this->token, 'count_segment', ['list' => 'Клиенты', 'segment' => ['active_days' => 30]])['structuredContent']['count'])->toBe(1)
        ->and(dataTool($this->token, 'count_segment', ['list' => 'Клиенты', 'segment' => ['active_days' => null, 'values' => []]])['structuredContent']['segment'])->toBeNull();

    expect(dataTool($this->token, 'count_segment', ['list' => 'Клиенты', 'segment' => ['field' => 'city']])['content'][0]['text'])->toContain('и field, и values');
});

it('creates and edits a draft campaign with the same rules as the admin form', function (): void {
    $created = dataTool($this->token, 'create_campaign', [
        'name' => 'Ноябрь', 'template' => 'digest', 'list' => 'Клиенты', 'segment' => ['field' => 'city', 'values' => ['Москва']],
    ]);
    expect($created['isError'])->toBeFalse();

    $campaign = $created['structuredContent']['campaign'];
    expect($campaign)->toMatchArray(['name' => 'Ноябрь', 'status' => 'draft', 'send_mode' => 'now', 'deliverable_count' => 1, 'stats' => null])
        ->and($campaign['template'])->toBe(['id' => $this->template->id, 'name' => 'Дайджест'])
        ->and($campaign['segment'])->toEqual(['field' => 'city', 'values' => ['Москва']]);

    $drip = dataTool($this->token, 'create_campaign', ['name' => 'Плавно', 'send_mode' => 'drip']);
    expect($drip['isError'])->toBeTrue()
        ->and($drip['content'][0]['text'])->toContain('сколько писем отправлять за раз');

    expect(dataTool($this->token, 'create_campaign', ['name' => 'X', 'template' => 'nope'])['content'][0]['text'])->toContain('не найдено')
        ->and(dataTool($this->token, 'create_campaign', ['name' => 'X', 'window_start' => '10:00', 'window_end' => '09:00'])['isError'])->toBeTrue();

    $updated = dataTool($this->token, 'update_campaign', [
        'campaign' => $campaign['id'], 'name' => 'Ноябрь, Москва', 'segment' => null,
        'send_mode' => 'drip', 'batch_size' => 100, 'batch_interval' => 30, 'window_start' => '09:00', 'window_end' => '18:00', 'window_days' => [1, 2, 3, 4, 5],
    ])['structuredContent']['campaign'];

    expect($updated)->toMatchArray(['name' => 'Ноябрь, Москва', 'segment' => null, 'send_mode' => 'drip', 'deliverable_count' => 2])
        ->and($updated['template']['id'])->toBe($this->template->id)
        ->and($updated['drip'])->toMatchArray(['batch_size' => 100, 'batch_interval' => 30, 'window_days' => [1, 2, 3, 4, 5]]);

    $back = dataTool($this->token, 'update_campaign', ['campaign' => $campaign['id'], 'send_mode' => 'now'])['structuredContent']['campaign'];
    expect($back['drip'])->toBeNull()
        ->and(Campaign::query()->find($campaign['id'])->batch_size)->toBeNull();

    $list = dataTool($this->token, 'list_campaigns', ['status' => 'draft'])['structuredContent'];
    expect($list['total'])->toBe(1)
        ->and(dataTool($this->token, 'get_campaign', ['campaign' => $campaign['id']])['structuredContent']['campaign']['name'])->toBe('Ноябрь, Москва');

    expect(auditActions())->toBe(['mcp.campaigns.store', 'mcp.campaigns.update', 'mcp.campaigns.update']);
});

it('sends a campaign test only to the key owner within the shared hourly limit', function (): void {
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);

    $result = dataTool($this->token, 'send_campaign_test', ['campaign' => $campaign->id, 'contact_email' => 'boris@example.com']);
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['to'])->toBe($this->editor->email);

    $message = $this->organization->messages()->latest('id')->first();
    expect($message->to_email)->toBe($this->editor->email)
        ->and($message->subject)->toContain('Борис')
        ->and($campaign->messages()->count())->toBe(0);
    Queue::assertPushed(SendMessageJob::class, 1);

    expect(dataTool($this->token, 'send_campaign_test', ['campaign' => $campaign->id, 'contact_email' => 'ghost@example.com'])['content'][0]['text'])
        ->toContain('Такого адреса нет в базе рассылки');

    foreach (range(3, 10) as $i) {
        dataTool($this->token, 'send_test', ['template' => 'digest']);
    }

    expect(dataTool($this->token, 'send_campaign_test', ['campaign' => $campaign->id])['content'][0]['text'])->toContain('Слишком много тестовых писем');
});

it('schedules a campaign only for later and lets it be cancelled', function (): void {
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);

    expect(dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => now()->addMinute()->toIso8601String()])['content'][0]['text'])
        ->toContain('не раньше чем через 5 минут')
        ->and(dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => now()->subDay()->toIso8601String()])['isError'])->toBeTrue()
        ->and(dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => now()->addYears(2)->toIso8601String()])['content'][0]['text'])->toContain('на год')
        ->and(dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => 'завтра утром'])['content'][0]['text'])->toContain('Не понял дату');

    $at = now()->addDay()->setTime(10, 0)->utc();
    $result = dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => $at->format('Y-m-d\TH:i:sP')]);
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['message'])->toContain('2 получателей')
        ->and($result['structuredContent']['campaign']['status'])->toBe('scheduled');

    $campaign->refresh();
    expect($campaign->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($campaign->scheduled_at->equalTo($at))->toBeTrue()
        ->and($campaign->recipients_count)->toBe(2);
    Queue::assertPushed(SendCampaignJob::class, 1);

    expect(dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => $at->toIso8601String()])['content'][0]['text'])->toContain('уже запланирована')
        ->and(dataTool($this->token, 'update_campaign', ['campaign' => $campaign->id, 'name' => 'Другое'])['content'][0]['text'])->toContain('только черновик');

    $cancelled = dataTool($this->token, 'cancel_campaign', ['campaign' => $campaign->id]);
    expect($cancelled['structuredContent']['campaign']['status'])->toBe('cancelled')
        ->and(dataTool($this->token, 'cancel_campaign', ['campaign' => $campaign->id])['content'][0]['text'])->toContain('только запущенную');

    expect(auditActions())->toBe(['mcp.campaigns.schedule', 'mcp.campaigns.cancel']);
});

it('reads a time without an offset as Moscow time and keeps an unready draft untouched', function (): void {
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);
    $local = now('Europe/Moscow')->addDays(2)->setTime(9, 30);

    dataTool($this->token, 'schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => $local->format('Y-m-d H:i')]);
    expect($campaign->fresh()->scheduled_at->equalTo($local))->toBeTrue();

    $empty = $this->organization->campaigns()->create(['name' => 'Без письма', 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);
    $refused = dataTool($this->token, 'schedule_campaign', ['campaign' => $empty->id, 'scheduled_at' => $local->format('Y-m-d H:i')]);

    expect($refused['isError'])->toBeTrue()
        ->and($refused['content'][0]['text'])->toContain('Выберите контент')
        ->and($empty->fresh()->status)->toBe(Campaign::STATUS_DRAFT)
        ->and($empty->fresh()->scheduled_at)->toBeNull();
});

it('lets viewers and designers only read lists, contacts and campaigns', function (string $role): void {
    $token = app(McpTokens::class)->issue(dataUser($role), 'Ключ')['plain'];
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);

    expect(dataTool($token, 'list_lists')['isError'])->toBeFalse()
        ->and(dataTool($token, 'list_contacts', ['list' => 'Клиенты'])['isError'])->toBeFalse()
        ->and(dataTool($token, 'get_campaign', ['campaign' => $campaign->id])['isError'])->toBeFalse();

    $calls = [
        ['create_list', ['name' => 'Новая']],
        ['update_list', ['list' => 'Клиенты', 'name' => 'Новая']],
        ['upsert_contacts', ['list' => 'Клиенты', 'contacts' => [['email' => 'x@example.com']]]],
        ['create_campaign', ['name' => 'Новая']],
        ['update_campaign', ['campaign' => $campaign->id, 'name' => 'Новая']],
        ['send_campaign_test', ['campaign' => $campaign->id]],
        ['schedule_campaign', ['campaign' => $campaign->id, 'scheduled_at' => now()->addDay()->toIso8601String()]],
        ['cancel_campaign', ['campaign' => $campaign->id]],
    ];

    foreach ($calls as [$tool, $args]) {
        $result = dataTool($token, $tool, $args);
        expect($result['isError'])->toBeTrue()
            ->and($result['content'][0]['text'])->toBe('Ваша роль не позволяет менять базы, подписчиков и рассылки');
    }

    expect($campaign->fresh()->status)->toBe(Campaign::STATUS_DRAFT)
        ->and($this->list->contacts()->count())->toBe(3)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['viewer', 'designer']);

it('keeps lists and campaigns of other organizations out of reach', function (): void {
    $other = Organization::create(['name' => 'Чужая', 'slug' => 'other']);
    $otherList = $other->lists()->create(['name' => 'Чужая база']);
    $otherCampaign = $other->campaigns()->create(['name' => 'Чужая рассылка', 'status' => Campaign::STATUS_DRAFT]);

    expect(dataTool($this->token, 'list_contacts', ['list' => $otherList->id])['content'][0]['text'])->toContain('не найдена')
        ->and(dataTool($this->token, 'upsert_contacts', ['list' => 'Чужая база', 'contacts' => [['email' => 'x@example.com']]])['isError'])->toBeTrue()
        ->and(dataTool($this->token, 'get_campaign', ['campaign' => $otherCampaign->id])['content'][0]['text'])->toContain('не найдена')
        ->and(dataTool($this->token, 'cancel_campaign', ['campaign' => $otherCampaign->id])['isError'])->toBeTrue()
        ->and(dataTool($this->token, 'list_lists')['structuredContent']['lists'])->toHaveCount(1)
        ->and($otherList->contacts()->count())->toBe(0);
});
