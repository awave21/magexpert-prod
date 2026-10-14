<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Organization;
use App\Sender\Models\Segment;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class]);

    $this->organization = Organization::create(['name' => 'Студия', 'slug' => 'studio']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass', 'role' => 'admin']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
    $this->list = $this->organization->lists()->create(['name' => 'Клиенты']);

    $add = fn (string $email, array $data = [], array $tags = [], ?int $openedDaysAgo = null) => $this->list->contacts()->create([
        'organization_id' => $this->organization->id,
        'email' => $email,
        'data' => $data,
        'tags' => $tags ?: null,
        'last_opened_at' => $openedDaysAgo === null ? null : now()->subDays($openedDaysAgo),
    ]);

    $add('msk-active@example.com', ['city' => 'Москва'], ['event-214'], 5);
    $add('msk-webinar@example.com', ['city' => 'Москва'], ['event-231'], 10);
    $add('spb-active@example.com', ['city' => 'Санкт-Петербург'], [], 3);
    $add('msk-silent@example.com', ['city' => 'Москва'], [], 200);
    $add('nocity@example.com', [], [], null);
});

function segmentsApi(string $path = ''): string
{
    return '/api/sender/v1/admin/segments'.$path;
}

/**
 * @param  list<array<string, mixed>>  $conditions
 * @return list<string>
 */
function segmentEmails(array $conditions): array
{
    return Contact::query()->matchingConditions($conditions)->orderBy('email')->pluck('email')->all();
}

it('selects contacts by activity, field and tags, including exclusions', function (): void {
    expect(segmentEmails([['type' => 'activity', 'op' => 'opened', 'days' => 90]]))
        ->toBe(['msk-active@example.com', 'msk-webinar@example.com', 'spb-active@example.com'])
        ->and(segmentEmails([['type' => 'activity', 'op' => 'not_opened', 'days' => 90]]))
        ->toBe(['msk-silent@example.com', 'nocity@example.com'])
        ->and(segmentEmails([['type' => 'field', 'field' => 'city', 'op' => 'not_in', 'values' => ['Москва']]]))
        ->toBe(['nocity@example.com', 'spb-active@example.com'])
        ->and(segmentEmails([['type' => 'tag', 'op' => 'none', 'tags' => ['event-231']]]))
        ->toBe(['msk-active@example.com', 'msk-silent@example.com', 'nocity@example.com', 'spb-active@example.com'])
        ->and(segmentEmails([
            ['type' => 'activity', 'op' => 'opened', 'days' => 90],
            ['type' => 'field', 'field' => 'city', 'op' => 'in', 'values' => ['Москва']],
            ['type' => 'tag', 'op' => 'none', 'tags' => ['event-231']],
        ]))->toBe(['msk-active@example.com']);
});

it('creates a segment, shows who is in it and counts recipients', function (): void {
    $id = $this->withToken($this->token)->postJson(segmentsApi(), [
        'name' => 'Москва, активные',
        'list_id' => $this->list->id,
        'conditions' => [
            ['type' => 'activity', 'op' => 'opened', 'days' => 90],
            ['type' => 'field', 'field' => 'city', 'op' => 'in', 'values' => ['Москва']],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.list_count', 5)
        ->assertJsonPath('data.list.name', 'Клиенты')
        ->json('data.id');

    $this->withToken($this->token)->getJson(segmentsApi())->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->token)->getJson(segmentsApi("/{$id}/contacts"))->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.email', 'msk-webinar@example.com');
    $this->withToken($this->token)->getJson(segmentsApi("/{$id}/contacts?q=active"))->assertOk()->assertJsonPath('meta.total', 1);
});

it('rejects empty, repeated and mismatched conditions and a change of the base', function (): void {
    $this->withToken($this->token)->postJson(segmentsApi(), ['name' => 'Пустой', 'list_id' => $this->list->id, 'conditions' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['conditions']);

    $this->withToken($this->token)->postJson(segmentsApi(), [
        'name' => 'Двойной',
        'list_id' => $this->list->id,
        'conditions' => [
            ['type' => 'tag', 'op' => 'any', 'tags' => ['event-214']],
            ['type' => 'tag', 'op' => 'in', 'tags' => ['event-231']],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['conditions.1.type', 'conditions.1.op']);

    $segment = $this->organization->segments()->create(['name' => 'С тегом', 'list_id' => $this->list->id, 'conditions' => [['type' => 'tag', 'op' => 'any', 'tags' => ['event-214']]]]);
    $other = $this->organization->lists()->create(['name' => 'Другая']);

    $this->withToken($this->token)->putJson(segmentsApi("/{$segment->id}"), [
        'name' => 'С тегом',
        'list_id' => $other->id,
        'conditions' => [['type' => 'tag', 'op' => 'any', 'tags' => ['event-214']]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['list_id']);
});

it('sends a campaign only to the chosen segment', function (): void {
    $segment = $this->organization->segments()->create([
        'name' => 'Москва без вебинара',
        'list_id' => $this->list->id,
        'conditions' => [
            ['type' => 'field', 'field' => 'city', 'op' => 'in', 'values' => ['Москва']],
            ['type' => 'tag', 'op' => 'none', 'tags' => ['event-231']],
        ],
    ]);

    $response = $this->withToken($this->token)->postJson('/api/sender/v1/admin/campaigns', ['name' => 'Подборка', 'segment_id' => $segment->id])
        ->assertCreated()
        ->assertJsonPath('data.list.id', $this->list->id)
        ->assertJsonPath('data.saved_segment', ['id' => $segment->id, 'name' => 'Москва без вебинара', 'deleted' => false]);

    $this->withToken($this->token)->getJson('/api/sender/v1/admin/campaigns/'.$response->json('data.id'))
        ->assertOk()->assertJsonPath('data.subscribed_count', 2);

    $campaign = Campaign::findOrFail($response->json('data.id'));
    expect($this->list->contacts()->deliverable()->forCampaign($campaign)->orderBy('email')->pluck('email')->all())
        ->toBe(['msk-active@example.com', 'msk-silent@example.com']);

    $campaign->update(['list_id' => $this->organization->lists()->create(['name' => 'Другая'])->id]);
    expect($campaign->refresh()->segment_id)->toBeNull();
});

it('leaves drafts without recipients when their segment is deleted', function (): void {
    $segment = $this->organization->segments()->create(['name' => 'Активные', 'list_id' => $this->list->id, 'conditions' => [['type' => 'activity', 'op' => 'opened', 'days' => 30]]]);
    $draft = $this->organization->campaigns()->create(['name' => 'Черновик', 'list_id' => $this->list->id, 'segment_id' => $segment->id, 'status' => Campaign::STATUS_DRAFT]);

    $this->withToken($this->token)->getJson(segmentsApi("/{$segment->id}"))->assertOk()
        ->assertJsonPath('data.campaigns.0.name', 'Черновик')
        ->assertJsonPath('data.campaigns_count', 1);

    $this->withToken($this->token)->deleteJson(segmentsApi("/{$segment->id}"))->assertOk();

    expect(Segment::count())->toBe(0)
        ->and($draft->refresh()->list_id)->toBeNull()
        ->and($draft->segment_id)->toBeNull()
        ->and($draft->segment_name)->toBeNull()
        ->and($this->list->contacts()->count())->toBe(5);
});

it('asks what to do with running campaigns when their segment is deleted', function (): void {
    $segment = $this->organization->segments()->create(['name' => 'Москва', 'list_id' => $this->list->id, 'conditions' => [['type' => 'field', 'field' => 'city', 'op' => 'in', 'values' => ['Москва']]]]);
    $scheduled = $this->organization->campaigns()->create(['name' => 'Осенняя подборка', 'list_id' => $this->list->id, 'segment_id' => $segment->id, 'status' => Campaign::STATUS_DRAFT]);
    $scheduled->forceFill(['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay(), 'recipients_count' => 3])->save();

    $this->withToken($this->token)->deleteJson(segmentsApi("/{$segment->id}"))
        ->assertUnprocessable()->assertJsonValidationErrors(['campaigns']);

    $this->withToken($this->token)->deleteJson(segmentsApi("/{$segment->id}"), ['campaigns' => 'keep'])
        ->assertUnprocessable()->assertJsonPath('message', 'Для рассылки «Осенняя подборка» по этому сегменту ещё готовятся письма. Подождите несколько минут или отмените рассылку');

    $scheduled->forceFill(['prepared_at' => now()])->save();
    $this->withToken($this->token)->deleteJson(segmentsApi("/{$segment->id}"), ['campaigns' => 'keep'])->assertOk();

    expect($scheduled->refresh()->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($scheduled->list_id)->toBe($this->list->id)
        ->and($scheduled->recipients_count)->toBe(3);

    $this->withToken($this->token)->getJson("/api/sender/v1/admin/campaigns/{$scheduled->id}")->assertOk()
        ->assertJsonPath('data.saved_segment', ['id' => null, 'name' => 'Москва', 'deleted' => true]);
});

it('cancels running campaigns together with the segment when asked, even while letters are being prepared', function (): void {
    $segment = $this->organization->segments()->create(['name' => 'Москва', 'list_id' => $this->list->id, 'conditions' => [['type' => 'field', 'field' => 'city', 'op' => 'in', 'values' => ['Москва']]]]);
    $sending = $this->organization->campaigns()->create(['name' => 'Анонс', 'list_id' => $this->list->id, 'segment_id' => $segment->id, 'status' => Campaign::STATUS_SENDING]);

    $this->withToken($this->token)->deleteJson(segmentsApi("/{$segment->id}"), ['campaigns' => 'cancel'])
        ->assertOk()->assertJsonPath('message', 'Сегмент удалён, рассылки отменены');

    expect($sending->refresh()->status)->toBe(Campaign::STATUS_CANCELLED)
        ->and($sending->segment_name)->toBe('Москва')
        ->and(Segment::count())->toBe(0);
});

it('counts a not yet saved segment for the editor', function (): void {
    $this->withToken($this->token)->postJson("/api/sender/v1/admin/lists/{$this->list->id}/count", [
        'conditions' => [['type' => 'field', 'field' => 'city', 'op' => 'not_in', 'values' => ['Москва']]],
    ])->assertOk()->assertJsonPath('data.count', 2);
});

it('does not show segments of another organization and lets a viewer only look', function (): void {
    $foreign = Organization::create(['name' => 'Чужая', 'slug' => 'foreign']);
    $foreignList = $foreign->lists()->create(['name' => 'Чужая база']);
    $segment = $foreign->segments()->create(['name' => 'Чужой', 'list_id' => $foreignList->id, 'conditions' => [['type' => 'activity', 'op' => 'opened', 'days' => 30]]]);

    $this->withToken($this->token)->getJson(segmentsApi("/{$segment->id}"))->assertNotFound();
    $this->withToken($this->token)->postJson('/api/sender/v1/admin/campaigns', ['name' => 'X', 'segment_id' => $segment->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['segment_id']);

    User::create(['organization_id' => $this->organization->id, 'name' => 'Зритель', 'email' => 'viewer@example.com', 'password' => 'secret-pass', 'role' => 'viewer']);
    $viewer = (new AuthService)->login('viewer@example.com', 'secret-pass')['token'];

    $this->withToken($viewer)->getJson(segmentsApi())->assertOk();
    $this->withToken($viewer)->postJson(segmentsApi(), ['name' => 'X', 'list_id' => $this->list->id, 'conditions' => [['type' => 'activity', 'op' => 'opened', 'days' => 30]]])
        ->assertForbidden();
});
