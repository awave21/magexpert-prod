<?php

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\DomainService;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    config(['sender.server_ip' => '62.217.181.42', 'sender.dkim_selector' => 'mail']);

    $this->dns = new class implements DnsLookup
    {
        /** @var array<string, list<string>> */
        public array $txt = [];

        /** @var array<string, list<string>> */
        public array $mx = [];

        /** @var array<string, list<string>> */
        public array $ns = [];

        public function txt(string $host): array
        {
            return $this->txt[$host] ?? [];
        }

        public function mx(string $domain): ?array
        {
            return $this->mx[$domain] ?? [];
        }

        public function ns(string $domain): ?array
        {
            return $this->ns[$domain] ?? [];
        }

        public function hasAddress(string $domain): bool
        {
            return true;
        }
    };
    $this->app->instance(DnsLookup::class, $this->dns);

    $this->organization = Organization::create(['name' => 'Клиника', 'slug' => 'clinic']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];
});

function dnsCheck(int $id): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson('/api/sender/v1/admin/domains/'.$id.'/dns-check')->assertOk();
}

function recordOf(Illuminate\Testing\TestResponse $response, string $key): array
{
    return collect($response->json('data.records'))->firstWhere('key', $key);
}

it('advises a fresh domain on reg.ru with mail.ru mail', function (): void {
    $this->dns->ns['clinic.ru'] = ['ns1.reg.ru', 'ns2.reg.ru'];
    $this->dns->mx['clinic.ru'] = ['emx.mail.ru'];
    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');

    $response = dnsCheck($domain->id)
        ->assertJsonPath('data.dns_provider.key', 'regru')
        ->assertJsonPath('data.mail_provider.key', 'mailru');

    expect(recordOf($response, 'verification'))->toMatchArray(['action' => 'add', 'name' => '_sender-verify'])
        ->and(recordOf($response, 'dkim'))->toMatchArray(['action' => 'add', 'name' => 'mail._domainkey'])
        ->and(recordOf($response, 'spf'))->toMatchArray(['action' => 'add', 'name' => '@', 'suggested' => 'v=spf1 ip4:62.217.181.42 include:_spf.mail.ru ~all'])
        ->and(recordOf($response, 'dmarc')['action'])->toBe('add');
});

it('merges an existing spf instead of adding a second one and keeps the existing dmarc', function (): void {
    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');
    $this->dns->txt['clinic.ru'] = ['v=spf1 include:_spf.mail.ru -all', 'google-site-verification=abc'];
    $this->dns->txt['_dmarc.clinic.ru'] = ['v=DMARC1; p=quarantine; rua=mailto:dmarc@clinic.ru'];
    $this->dns->txt['_sender-verify.clinic.ru'] = [$domain->verification_token];

    $response = dnsCheck($domain->id);

    expect(recordOf($response, 'spf'))->toMatchArray([
        'action' => 'replace',
        'current' => ['v=spf1 include:_spf.mail.ru -all'],
        'suggested' => 'v=spf1 ip4:62.217.181.42 include:_spf.mail.ru -all',
    ])
        ->and(recordOf($response, 'dmarc')['action'])->toBe('keep')
        ->and(recordOf($response, 'verification')['action'])->toBe('ok');
});

it('asks to merge several spf records into one', function (): void {
    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');
    $this->dns->txt['clinic.ru'] = ['v=spf1 include:_spf.mail.ru ~all', 'v=spf1 include:_spf.yandex.net ~all'];

    expect(recordOf(dnsCheck($domain->id), 'spf'))->toMatchArray([
        'action' => 'conflict',
        'suggested' => 'v=spf1 ip4:62.217.181.42 include:_spf.mail.ru include:_spf.yandex.net ~all',
    ]);
});

it('marks records that are already in place', function (): void {
    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');
    $this->dns->txt['clinic.ru'] = ['v=spf1 ip4:62.217.181.42 include:_spf.mail.ru ~all'];
    $this->dns->txt['mail._domainkey.clinic.ru'] = ['v=DKIM1; k=rsa; p='.$domain->dkim_public_key];

    $response = dnsCheck($domain->id);

    expect(recordOf($response, 'spf')['action'])->toBe('ok')
        ->and(recordOf($response, 'dkim')['action'])->toBe('ok');
});

it('picks another dkim name when yandex 360 already uses mail._domainkey', function (): void {
    $this->dns->mx['clinic.ru'] = ['mx.yandex.net'];
    $this->dns->ns['clinic.ru'] = ['dns1.yandex.net', 'dns2.yandex.net'];
    $this->dns->txt['mail._domainkey.clinic.ru'] = ['v=DKIM1; k=rsa; p=YANDEXKEY'];

    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');

    expect($domain->dkim_selector)->toBe('sender');

    $response = dnsCheck($domain->id)->assertJsonPath('data.dns_provider.key', 'yandex')->assertJsonPath('data.mail_provider.key', 'yandex');

    expect(recordOf($response, 'dkim'))->toMatchArray(['action' => 'add', 'name' => 'sender._domainkey']);
});

it('warns about a dkim conflict on an existing domain and names the mail provider', function (): void {
    $domain = app(DomainService::class)->add($this->organization, 'clinic.ru');
    $this->dns->mx['clinic.ru'] = ['mx.yandex.net'];
    $this->dns->txt['mail._domainkey.clinic.ru'] = ['v=DKIM1; k=rsa; p=YANDEXKEY'];

    expect(recordOf(dnsCheck($domain->id), 'dkim'))->toMatchArray(['action' => 'conflict'])
        ->and(recordOf(dnsCheck($domain->id), 'dkim')['note'])->toContain('Яндекс 360');
});

it('finds the dns provider of a subdomain at its parent', function (): void {
    $this->dns->ns['clinic.ru'] = ['ns3-l2.nic.ru'];
    $domain = app(DomainService::class)->add($this->organization, 'news.clinic.ru');

    $response = dnsCheck($domain->id)->assertJsonPath('data.dns_provider.key', 'nicru')->assertJsonPath('data.zone', 'clinic.ru');

    expect(recordOf($response, 'spf')['name'])->toBe('news')
        ->and(recordOf($response, 'dmarc')['name'])->toBe('_dmarc.news')
        ->and(recordOf($response, 'dkim')['name'])->toBe('mail._domainkey.news');
});

it('does not show dns of another organization', function (): void {
    $other = Organization::create(['name' => 'Чужая', 'slug' => 'other']);
    $domain = app(DomainService::class)->add($other, 'other.ru');

    $this->withToken($this->token)->getJson('/api/sender/v1/admin/domains/'.$domain->id.'/dns-check')->assertNotFound();
});
