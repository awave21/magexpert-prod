<?php

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use App\Sender\Services\EmailChecker;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Cache::flush();

    // домены с почтовым сервером; dead.ru существует без почты, broken.ru не отвечает
    $this->app->instance(DnsLookup::class, new class implements DnsLookup
    {
        public int $mxQueries = 0;

        public function txt(string $host): array
        {
            return [];
        }

        public function mx(string $domain): ?array
        {
            $this->mxQueries++;

            return match ($domain) {
                'clinic.ru', 'mediclinic.org', 'rb.ru' => ['mx.'.$domain],
                'nullmx.ru' => ['.'],
                'broken.ru' => null,
                default => [],
            };
        }

        public function hasAddress(string $domain): bool
        {
            return in_array($domain, ['dead.ru', 'webonly.ru'], true) ? $domain === 'webonly.ru' : false;
        }
    });

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
});

it('classifies addresses', function (string $email, ?string $status, ?string $hint = null): void {
    $result = app(EmailChecker::class)->check($email);

    expect($result['status'] ?? null)->toBe($status)
        ->and($result['hint'] ?? null)->toBe($hint);
})->with([
    'popular domain' => ['anna@mail.ru', 'ok'],
    'corporate domain with mx' => ['doctor@clinic.ru', 'ok'],
    'domain without mx but with a site (implicit mx)' => ['a@webonly.ru', 'ok'],
    'typo in a long domain' => ['anna@gmial.com', 'typo', 'anna@gmail.com'],
    'wrong tld' => ['anna@yandex.ri', 'typo', 'anna@yandex.ru'],
    'missing dot' => ['anna@mailru', 'invalid'],
    'look-alike without mail' => ['petr@gmailcom.ru', 'no_mx'],
    'gmail.ru means gmail.com' => ['anna@gmail.ru', 'typo', 'anna@gmail.com'],
    'short look-alike with mail server is real' => ['news@rb.ru', 'ok'],
    'disposable' => ['x@mailinator.com', 'disposable'],
    'disposable subdomain' => ['x@abc.0815.ru', 'disposable'],
    'domain without mail' => ['a@dead.ru', 'no_mx'],
    'null mx' => ['a@nullmx.ru', 'no_mx'],
    'role address' => ['info@clinic.ru', 'role'],
    'role with tag' => ['support+news@clinic.ru', 'role'],
    'broken syntax' => ['anna..ivanova@mail.ru', 'invalid'],
    'dns did not answer' => ['a@broken.ru', null],
]);

it('queries dns once per domain', function (): void {
    $checker = app(EmailChecker::class);
    foreach (['a@clinic.ru', 'b@clinic.ru', 'c@clinic.ru', 'd@mail.ru'] as $email) {
        $checker->check($email);
    }

    expect(app(DnsLookup::class)->mxQueries)->toBe(1);
});

it('checks addresses after import and shows the summary', function (): void {
    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/import', [
        'text' => "anna@mail.ru\npetr@gmial.com\nx@mailinator.com\ninfo@clinic.ru\nold@dead.ru\nwho@broken.ru",
    ])->assertOk()->assertJsonPath('data.added', 6);

    $this->withToken($this->token)->getJson('/api/sender/v1/admin/lists/'.$this->list->id)
        ->assertOk()
        ->assertJsonPath('data.checks', ['unchecked' => 1, 'ok' => 1, 'role' => 1, 'typo' => 1, 'disposable' => 1, 'no_mx' => 1, 'invalid' => 0])
        ->assertJsonPath('data.deliverable_count', 3);

    $this->withToken($this->token)->getJson('/api/sender/v1/admin/lists/'.$this->list->id.'/contacts?check=typo')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.check_hint', 'petr@gmail.com');
});

it('fixes a typo and removes a duplicate', function (): void {
    $typo = $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'petr@gmial.com', 'check_status' => 'typo', 'check_hint' => 'petr@gmail.com', 'checked_at' => now()]);
    $dup = $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@gmial.com', 'check_status' => 'typo', 'check_hint' => 'anna@gmail.com', 'checked_at' => now()]);
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@gmail.com']);

    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/contacts/'.$typo->id.'/fix')->assertOk();

    expect($typo->fresh()->email)->toBe('petr@gmail.com')
        ->and($typo->fresh()->check_status)->toBe('ok');

    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/fix-typos')
        ->assertOk()->assertJsonPath('data.fixed', 1);

    expect($dup->fresh())->toBeNull()
        ->and($this->list->contacts()->count())->toBe(2);

    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/contacts/'.$typo->id.'/fix')->assertUnprocessable();
});

it('checks a contact added by hand right away', function (): void {
    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/contacts', ['email' => 'a@dead.ru'])
        ->assertCreated()->assertJsonPath('data.check_status', 'no_mx');
});

it('rechecks the whole base on request', function (): void {
    $contact = $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'x@mailinator.com', 'check_status' => 'ok', 'checked_at' => now()]);

    $this->withToken($this->token)->postJson('/api/sender/v1/admin/lists/'.$this->list->id.'/check')->assertOk();

    expect($contact->fresh()->check_status)->toBe('disposable');
});

it('does not send a campaign to rejected addresses', function (): void {
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $template = $this->organization->templates()->create(['slug' => 'news', 'name' => 'Новости', 'subject' => 'Новости', 'body_html' => '<p>Привет</p>']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();
    $template->update(['sender_address_id' => $sender->id]);

    foreach (['ok@mail.ru' => 'ok', 'info@clinic.ru' => 'role', 'new@clinic.ru' => null, 'p@gmial.com' => 'typo', 'x@mailinator.com' => 'disposable', 'a@dead.ru' => 'no_mx'] as $email => $status) {
        $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => $email, 'check_status' => $status, 'checked_at' => $status ? now() : null]);
    }

    $campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);

    expect(app(CampaignService::class)->start($campaign)->recipients_count)->toBe(3);

    expect(Message::query()->where('campaign_id', $campaign->id)->pluck('to_email')->sort()->values()->all())
        ->toBe(['info@clinic.ru', 'new@clinic.ru', 'ok@mail.ru']);
});
