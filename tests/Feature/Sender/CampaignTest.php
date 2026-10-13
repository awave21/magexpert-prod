<?php

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
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    // проверка адресов ходит в DNS: её покрывает EmailCheckTest
    Queue::fake([CheckContactsJob::class]);

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
        'name' => 'MagExpert',
    ]);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'digest',
        'name' => 'Дайджест',
        'subject' => 'Новости для {{ name }}',
        'body_html' => '<html><body><p>Здравствуйте, {{ name }} из {{ city }}!</p></body></html>',
        'body_text' => 'Здравствуйте, {{ name }}!',
        'sender_address_id' => $sender->id,
    ]);

    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
    $this->other = Organization::create(['name' => 'Other', 'slug' => 'other']);
});

function senderAdmin(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

it('creates, renames and lists bases with counts', function (): void {
    $this->withToken($this->token)->postJson(senderAdmin('/lists'), ['name' => '  Клиенты  '])
        ->assertCreated()->assertJsonPath('data.name', 'Клиенты');

    $this->withToken($this->token)->postJson(senderAdmin('/lists'), ['name' => 'Клиенты'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'a@example.com']);
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'b@example.com', 'unsubscribed_at' => now()]);

    $this->withToken($this->token)->getJson(senderAdmin('/lists'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Врачи')
        ->assertJsonPath('data.0.contacts_count', 2)
        ->assertJsonPath('data.0.subscribed_count', 1);

    $this->withToken($this->token)->putJson(senderAdmin('/lists/'.$this->list->id), ['name' => 'Врачи 2026'])
        ->assertOk()->assertJsonPath('data.name', 'Врачи 2026');
});

it('imports a csv with a header, extra columns and bad rows', function (): void {
    $csv = "Email;Имя;Город\nanna@example.com;Анна;Москва\nnot-an-email;Пётр;Казань\nIVAN@Example.com;Иван;\n\n";

    $this->withToken($this->token)->post(senderAdmin('/lists/'.$this->list->id.'/import'), [
        'file' => UploadedFile::fake()->createWithContent('base.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.columns', ['gorod']);

    $anna = $this->list->contacts()->where('email', 'anna@example.com')->first();

    expect($anna->name)->toBe('Анна')
        ->and($anna->data)->toBe(['gorod' => 'Москва'])
        ->and($this->list->contacts()->where('email', 'ivan@example.com')->exists())->toBeTrue();

    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), ['text' => "anna@example.com;Анна Иванова\nnew@example.com;Новый"])
        ->assertOk()
        ->assertJsonPath('data.added', 1)
        ->assertJsonPath('data.updated', 1);

    expect($anna->fresh()->name)->toBe('Анна Иванова')
        ->and($anna->fresh()->data)->toBe(['gorod' => 'Москва']);
});

it('imports a windows-1251 csv saved from excel and a plain list of addresses', function (): void {
    $csv = mb_convert_encoding("email;имя\nolga@example.com;Ольга\n", 'Windows-1251', 'UTF-8');

    $this->withToken($this->token)->post(senderAdmin('/lists/'.$this->list->id.'/import'), [
        'file' => UploadedFile::fake()->createWithContent('excel.csv', $csv),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.added', 1);

    expect($this->list->contacts()->where('email', 'olga@example.com')->value('name'))->toBe('Ольга');

    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), ['text' => "one@example.com\ntwo@example.com three@example.com"])
        ->assertOk()->assertJsonPath('data.added', 3);
});

it('shows the columns of a file before the import with samples and a guess', function (): void {
    $csv = "Почта;ФИО;Город;Стаж\nanna@example.com;Анна;Москва;5\npetr@example.com;Пётр;;12\n";

    $this->withToken($this->token)->post(senderAdmin('/lists/import-preview'), [
        'file' => UploadedFile::fake()->createWithContent('base.csv', $csv),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.rows', 2)
        ->assertJsonPath('data.header', true)
        ->assertJsonPath('data.columns.0', ['index' => 0, 'header' => 'Почта', 'samples' => ['anna@example.com', 'petr@example.com'], 'role' => 'email', 'key' => 'pocta'])
        ->assertJsonPath('data.columns.1.role', 'name')
        ->assertJsonPath('data.columns.2', ['index' => 2, 'header' => 'Город', 'samples' => ['Москва'], 'role' => 'field', 'key' => 'gorod'])
        ->assertJsonPath('data.columns.3.key', 'staz')
        ->assertJsonPath('data.sample_rows', [['anna@example.com', 'Анна', 'Москва', '5'], ['petr@example.com', 'Пётр', '', '12']]);

    // список без заголовка
    $this->withToken($this->token)->postJson(senderAdmin('/lists/import-preview'), ['text' => "one@example.com;Один\ntwo@example.com;Два"])
        ->assertOk()
        ->assertJsonPath('data.header', false)
        ->assertJsonPath('data.rows', 2)
        ->assertJsonPath('data.columns.0.role', 'email')
        ->assertJsonPath('data.columns.1.role', 'name');

    expect($this->list->contacts()->count())->toBe(0);
});

it('imports with the chosen mapping and skips columns left out', function (): void {
    $csv = "Контакт;Кто;Город;Внутренний id\nМосква;anna@example.com;Анна;17\nКазань;petr@example.com;Пётр;18\n";
    $mapping = ['email' => 1, 'name' => 2, 'header' => true, 'fields' => ['0' => 'city']];

    $this->withToken($this->token)->post(senderAdmin('/lists/'.$this->list->id.'/import'), [
        'file' => UploadedFile::fake()->createWithContent('base.csv', $csv),
        'mapping' => json_encode($mapping),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.rows', 2)
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.columns', ['city']);

    $anna = $this->list->contacts()->where('email', 'anna@example.com')->first();
    expect($anna->name)->toBe('Анна')->and($anna->data)->toBe(['city' => 'Москва']);

    // без заголовка первая строка тоже загружается
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), [
        'text' => "olga@example.com,Ольга\nivan@example.com,Иван",
        'mapping' => ['email' => 0, 'name' => null, 'header' => false, 'fields' => ['1' => 'first_name']],
    ])->assertOk()->assertJsonPath('data.added', 2);

    expect($this->list->contacts()->where('email', 'olga@example.com')->first())
        ->name->toBeNull()
        ->data->toBe(['first_name' => 'Ольга']);
});

it('rejects a broken mapping', function (array $mapping, string $error): void {
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), ['text' => 'a@example.com;A', 'mapping' => $mapping])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'нет колонки адреса' => [['name' => 1], 'mapping.email'],
    'имя в колонке адреса' => [['email' => 0, 'name' => 0], 'mapping.name'],
    'кириллица в переменной' => [['email' => 0, 'fields' => ['1' => 'город']], 'mapping.fields.1'],
    'одинаковые переменные' => [['email' => 0, 'fields' => ['1' => 'city', '2' => 'city']], 'mapping.fields.1'],
]);

it('lets only editors look at a file before the import', function (): void {
    User::create(['organization_id' => $this->organization->id, 'name' => 'Зритель', 'email' => 'viewer@example.com', 'password' => 'secret-pass', 'role' => 'viewer']);
    $viewer = (new AuthService)->login('viewer@example.com', 'secret-pass')['token'];

    $this->withToken($viewer)->postJson(senderAdmin('/lists/import-preview'), ['text' => 'a@example.com'])->assertForbidden();
});

it('rejects an import without a file or text and a non-csv file', function (): void {
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), [])
        ->assertUnprocessable()->assertJsonValidationErrors('text');

    $this->withToken($this->token)->post(senderAdmin('/lists/'.$this->list->id.'/import'), [
        'file' => UploadedFile::fake()->create('base.xlsx', 10),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('adds, searches and deletes contacts in a base', function (): void {
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/contacts'), ['email' => ' Anna@Example.com ', 'name' => 'Анна'])
        ->assertCreated()->assertJsonPath('data.email', 'anna@example.com');
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/contacts'), ['email' => 'petr@example.com'])->assertCreated();

    $this->withToken($this->token)->getJson(senderAdmin('/lists/'.$this->list->id.'/contacts?q=анна'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

    $id = $this->list->contacts()->where('email', 'petr@example.com')->value('id');
    $this->withToken($this->token)->deleteJson(senderAdmin('/lists/'.$this->list->id.'/contacts/'.$id))->assertOk();

    expect($this->list->contacts()->count())->toBe(1);
});

it('does not show bases of another organization', function (): void {
    $foreign = $this->other->lists()->create(['name' => 'Чужая']);

    $this->withToken($this->token)->getJson(senderAdmin('/lists/'.$foreign->id))->assertNotFound();
    $this->withToken($this->token)->getJson(senderAdmin('/lists/'.$foreign->id.'/contacts'))->assertNotFound();
    $this->withToken($this->token)->postJson(senderAdmin('/campaigns'), ['name' => 'Тест', 'list_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors('list_id');
});

it('creates a campaign draft and refuses to send without content or subscribers', function (): void {
    $id = $this->withToken($this->token)->postJson(senderAdmin('/campaigns'), ['name' => 'Ноябрь'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->withToken($this->token)->postJson(senderAdmin("/campaigns/{$id}/send"))
        ->assertUnprocessable()->assertJsonValidationErrors(['template_id', 'list_id']);

    $this->withToken($this->token)->putJson(senderAdmin("/campaigns/{$id}"), ['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id])
        ->assertOk()->assertJsonPath('data.subscribed_count', 0);

    $this->withToken($this->token)->postJson(senderAdmin("/campaigns/{$id}/send"))
        ->assertUnprocessable()->assertJsonValidationErrors('list_id');
});

it('sends a campaign to subscribed contacts only, once per address', function (): void {
    Queue::fake();

    foreach (['anna@example.com' => 'Анна', 'petr@example.com' => 'Пётр', 'gone@example.com' => 'Ушёл', 'blocked@example.com' => 'Блок'] as $email => $name) {
        $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => $email, 'name' => $name, 'data' => ['city' => 'Москва']]);
    }
    $this->list->contacts()->where('email', 'gone@example.com')->update(['unsubscribed_at' => now()]);
    Suppression::create(['organization_id' => $this->organization->id, 'email' => 'blocked@example.com', 'reason' => 'bounce']);

    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id]);

    $this->withToken($this->token)->postJson(senderAdmin("/campaigns/{$campaign->id}/send"))
        ->assertOk()->assertJsonPath('data.status', 'sending')->assertJsonPath('data.recipients_count', 2);

    Queue::assertPushedOn('sender', SendCampaignJob::class);

    // подготовка, запущенная дважды (повтор задачи), не дублирует письма
    $service = app(CampaignService::class);
    $service->prepare($campaign->fresh());
    $service->prepare($campaign->fresh());

    $messages = Message::query()->where('campaign_id', $campaign->id)->get();

    // адрес из блок-листа в рассылку не попадает вовсе
    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('to_email')->sort()->values()->all())->toBe(['anna@example.com', 'petr@example.com'])
        ->and($messages->firstWhere('to_email', 'anna@example.com')->from_email)->toBe('news@mag-expert.ru')
        ->and($messages->firstWhere('to_email', 'anna@example.com')->data['unsubscribe_url'])->toContain('/unsubscribe/')
        ->and($messages->firstWhere('to_email', 'anna@example.com')->data['first_name'])->toBe('Анна')
        ->and($campaign->fresh()->status)->toBe(Campaign::STATUS_SENDING);

    Queue::assertPushedOn('sender', SendMessageJob::class);

    $this->withToken($this->token)->getJson(senderAdmin("/campaigns/{$campaign->id}"))
        ->assertOk()->assertJsonPath('data.stats.queued', 2)->assertJsonPath('data.stats.blocked', 0)->assertJsonPath('data.stats.pending', 0);

    $this->withToken($this->token)->putJson(senderAdmin("/campaigns/{$campaign->id}"), ['name' => 'Другое'])->assertUnprocessable();
});

it('adds the unsubscribe link and headers to campaign mail', function (): void {
    $contact = $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com', 'name' => 'Анна', 'data' => ['city' => 'Казань']]);
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_SENDING]);

    app(CampaignService::class)->prepare($campaign);
    $message = Message::query()->where('campaign_id', $campaign->id)->firstOrFail();

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $url = $message->data['unsubscribe_url'];

    expect($sent->getHtmlBody())->toContain('Здравствуйте, Анна из Казань!')
        ->and($sent->getHtmlBody())->toContain($url)
        ->and($sent->getHtmlBody())->toEndWith('</body></html>')
        ->and($sent->getTextBody())->toContain('Отписаться от рассылки: '.$url)
        ->and($sent->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toBe('<'.$url.'>')
        ->and($sent->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');

    $path = parse_url($url, PHP_URL_PATH);

    $this->get($path)->assertOk()->assertSee('Отписаться от рассылки?');
    expect($contact->fresh()->unsubscribed_at)->toBeNull();

    $this->post($path)->assertOk()->assertSee('Вы отписались');
    expect($contact->fresh()->unsubscribed_at)->not->toBeNull();

    $this->get($path)->assertOk()->assertSee('Вы уже отписаны');
});

it('adds a system unsubscribe link to every letter, transactional included', function (): void {
    $message = app(App\Sender\Services\MessageService::class)->send($this->organization, $this->template, 'anna@example.com', data: ['name' => 'Анна']);

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($message->data['unsubscribe_url'])->toContain('/unsubscribe/'.$message->uuid)
        ->and($sent->getHtmlBody())->toContain($message->data['unsubscribe_url'])
        ->and($sent->getTextBody())->toContain($message->data['unsubscribe_url'])
        ->and($sent->getHeaders()->has('List-Unsubscribe'))->toBeFalse();

    // отписка по этой ссылке работает, служебные письма после неё по-прежнему уходят
    $this->post('/sender/unsubscribe/'.$message->uuid)->assertOk()->assertSee('Вы отписались');
});

it('shows an error page for an unknown unsubscribe link', function (): void {
    $this->get('/sender/unsubscribe/'.Illuminate\Support\Str::uuid())->assertStatus(410);
});

it('keeps contacts of a deleted campaign but deletes contacts with their base', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'a@example.com']);
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'list_id' => $this->list->id]);

    $this->withToken($this->token)->deleteJson(senderAdmin("/campaigns/{$campaign->id}"))->assertOk();
    expect(Contact::query()->count())->toBe(1);

    $this->withToken($this->token)->deleteJson(senderAdmin('/lists/'.$this->list->id))->assertOk();
    expect(Contact::query()->count())->toBe(0);
});

it('reports duplicates in the file and skipped lines with their numbers', function (): void {
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'old@example.com']);

    $csv = "email;имя\nAnna@Example.com;Анна\n\nnot-an-email;Пётр\nanna@example.com;Анна Иванова\nOLD@example.com;Старый\nANNA@EXAMPLE.COM;Анна И.";

    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), ['text' => $csv])
        ->assertOk()
        ->assertJsonPath('data.rows', 5)
        ->assertJsonPath('data.added', 1)
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.duplicates', 2)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.skipped_rows', [['line' => 4, 'value' => 'not-an-email; Пётр']])
        ->assertJsonPath('data.duplicate_rows', [
            ['line' => 5, 'email' => 'anna@example.com', 'first_line' => 2],
            ['line' => 7, 'email' => 'anna@example.com', 'first_line' => 2],
        ]);

    expect($this->list->contacts()->pluck('email')->sort()->values()->all())->toBe(['anna@example.com', 'old@example.com'])
        ->and($this->list->contacts()->where('email', 'anna@example.com')->value('name'))->toBe('Анна И.');
});

it('numbers addresses pasted on one line separated by spaces', function (): void {
    $this->withToken($this->token)->postJson(senderAdmin('/lists/'.$this->list->id.'/import'), ['text' => "a@example.com b@example.com\nbad a@example.com"])
        ->assertOk()
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.skipped_rows.0.line', 2)
        ->assertJsonPath('data.duplicate_rows.0', ['line' => 2, 'email' => 'a@example.com', 'first_line' => 1]);
});

it('keeps unsubscribe links working after the campaign is deleted', function (): void {
    Queue::fake();
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@example.com', 'name' => 'Анна']);
    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_SENDING]);
    app(CampaignService::class)->prepare($campaign);
    $message = Message::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $campaign->update(['status' => Campaign::STATUS_SENT]);

    $this->withToken($this->token)->deleteJson(senderAdmin("/campaigns/{$campaign->id}"))->assertOk();
    expect($message->fresh()->campaign_id)->toBeNull();

    $this->post('/sender/unsubscribe/'.$message->uuid)->assertOk()->assertSee('Вы отписались');
    expect(Suppression::query()->where('email', 'anna@example.com')->value('reason'))->toBe(Suppression::REASON_UNSUBSCRIBE);

    // у служебного письма ссылка отписки тоже есть, а неизвестная ссылка не действует
    $service = app(App\Sender\Services\MessageService::class)->send($this->organization, $this->template, 'petr@example.com');
    expect($service->data['unsubscribe_url'])->toContain('/unsubscribe/'.$service->uuid);
    $this->post('/sender/unsubscribe/'.Illuminate\Support\Str::uuid())->assertStatus(410);
});
