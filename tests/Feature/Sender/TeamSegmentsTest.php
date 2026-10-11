<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class]);

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->admin = User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass', 'role' => 'admin']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();
    $this->template = $this->organization->templates()->create(['slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости', 'body_html' => '<p>Текст</p>', 'sender_address_id' => $sender->id]);
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
});

function teamApi(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

function loginAs(string $role): string
{
    User::create(['organization_id' => test()->organization->id, 'name' => $role, 'email' => "{$role}@example.com", 'password' => 'secret-pass', 'role' => $role]);

    return (new AuthService)->login("{$role}@example.com", 'secret-pass')['token'];
}

it('lets a viewer only look and an editor work with content but not with domains and keys', function (): void {
    $viewer = loginAs('viewer');
    $editor = loginAs('editor');

    $this->withToken($viewer)->getJson(teamApi('/campaigns'))->assertOk();
    $this->withToken($viewer)->postJson(teamApi("/templates/{$this->template->id}/preview"), ['data' => []])->assertOk();
    $this->withToken($viewer)->postJson(teamApi('/lists'), ['name' => 'Новая'])->assertForbidden()->assertJsonPath('message', 'У вас доступ только для просмотра');

    $this->withToken($editor)->postJson(teamApi('/lists'), ['name' => 'Новая'])->assertCreated();
    $this->withToken($editor)->postJson(teamApi('/api-keys'), ['name' => 'Ключ'])->assertForbidden()->assertJsonPath('message', 'Это может делать только администратор организации');
    $this->withToken($editor)->postJson(teamApi('/domains'), ['domain' => 'other.ru'])->assertForbidden();
    $this->withToken($editor)->getJson(teamApi('/users'))->assertOk();
    $this->withToken($editor)->postJson(teamApi('/users'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
});

it('adds people to the team with a one-time password and keeps at least one admin', function (): void {
    $response = $this->withToken($this->token)->postJson(teamApi('/users'), ['name' => 'Ольга', 'email' => 'Olga@Example.com', 'role' => 'editor'])
        ->assertCreated()->assertJsonPath('data.role', 'editor')->assertJsonPath('data.email', 'olga@example.com');

    expect((new AuthService)->login('olga@example.com', $response->json('password')))->not->toBeNull();

    $olga = User::query()->where('email', 'olga@example.com')->firstOrFail();
    $this->withToken($this->token)->putJson(teamApi("/users/{$olga->id}"), ['is_active' => false])->assertOk();
    expect($olga->fresh()->is_active)->toBeFalse();

    $this->withToken($this->token)->putJson(teamApi("/users/{$this->admin->id}"), ['role' => 'viewer'])->assertStatus(422);

    $this->withToken($this->token)->postJson(teamApi('/users'), ['name' => 'Ольга', 'email' => 'olga@example.com', 'role' => 'editor'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('writes campaign launches, deletions and keys to the audit log with the author', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'a@example.com']);
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);
    Queue::fake();

    $this->withToken($this->token)->postJson(teamApi("/campaigns/{$campaign->id}/send"))->assertOk();
    $this->withToken($this->token)->postJson(teamApi('/api-keys'), ['name' => 'Сайт'])->assertCreated();
    $this->withToken($this->token)->deleteJson(teamApi("/lists/{$this->list->id}"))->assertOk();
    // неудачное действие не записывается
    $this->withToken($this->token)->postJson(teamApi("/campaigns/{$campaign->id}/send"))->assertUnprocessable();
    // черновики и просмотр не записываются
    $this->withToken($this->token)->putJson(teamApi("/templates/{$this->template->id}"), [])->assertStatus(422);

    expect(AuditLog::query()->count())->toBe(3);

    $this->withToken($this->token)->getJson(teamApi('/audit-log'))
        ->assertOk()
        ->assertJsonPath('data.0.action', 'Удалил базу')->assertJsonPath('data.0.subject', 'Врачи')->assertJsonPath('data.0.user', 'Админ')
        ->assertJsonPath('data.1.action', 'Создал API-ключ')->assertJsonPath('data.1.subject', 'Сайт')
        ->assertJsonPath('data.2.action', 'Запустил рассылку')->assertJsonPath('data.2.subject', 'Ноябрь');
});

it('sends a campaign only to the chosen segment of the base', function (): void {
    Queue::fake();
    $rows = [
        ['anna@example.com', 'Кардиолог', now()->subDays(10)],
        ['petr@example.com', 'Невролог', now()->subDays(10)],
        ['olga@example.com', 'Кардиолог', now()->subDays(200)],
        ['ivan@example.com', 'Кардиолог', null],
    ];
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'nodata@example.com', 'data' => []]);
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'stage@example.com', 'data' => ['stage' => '2']]);
    foreach ($rows as [$email, $specialization, $opened]) {
        $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => $email, 'data' => ['specialization' => $specialization], 'last_opened_at' => $opened]);
    }

    $this->withToken($this->token)->getJson(teamApi("/lists/{$this->list->id}/fields"))
        ->assertOk()->assertJsonPath('data.0.key', 'specialization')
        ->assertJsonPath('data.0.values.0', ['value' => 'Кардиолог', 'count' => 3])
        ->assertJsonPath('data.1', ['key' => 'stage', 'values' => [['value' => '2', 'count' => 1]]]);

    $segment = ['active_days' => 90, 'field' => 'specialization', 'values' => ['Кардиолог']];
    $this->withToken($this->token)->postJson(teamApi("/lists/{$this->list->id}/count"), ['segment' => $segment])
        ->assertOk()->assertJsonPath('data.count', 1);

    $id = $this->withToken($this->token)->postJson(teamApi('/campaigns'), ['name' => 'Кардиологам', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'segment' => $segment])
        ->assertCreated()->json('data.id');
    $this->withToken($this->token)->getJson(teamApi("/campaigns/{$id}"))->assertJsonPath('data.subscribed_count', 1);

    $this->withToken($this->token)->postJson(teamApi("/campaigns/{$id}/send"))->assertOk()->assertJsonPath('data.recipients_count', 1);
    app(CampaignService::class)->prepare(Campaign::query()->findOrFail($id));

    expect(Campaign::query()->findOrFail($id)->messages()->pluck('to_email')->all())->toBe(['anna@example.com']);

    $this->withToken($this->token)->postJson(teamApi('/campaigns'), ['name' => 'X', 'segment' => ['field' => 'specialization']])
        ->assertUnprocessable()->assertJsonValidationErrors('segment.values');
    $this->withToken($this->token)->postJson(teamApi('/campaigns'), ['name' => 'X', 'segment' => ['field' => "x'); drop", 'values' => ['a']]])
        ->assertUnprocessable()->assertJsonValidationErrors('segment.field');
});
