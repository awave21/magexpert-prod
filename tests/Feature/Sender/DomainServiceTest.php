<?php

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Services\DomainService;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
});

function fakeDns(array $records): DnsLookup
{
    return new class($records) implements DnsLookup
    {
        public function __construct(private array $records) {}

        public function txt(string $host): array
        {
            return $this->records[$host] ?? [];
        }

        public function mx(string $domain): ?array
        {
            return [];
        }

        public function hasAddress(string $domain): bool
        {
            return false;
        }
    };
}

it('adds a domain with a token and dkim keys', function (): void {
    $domain = (new DomainService(fakeDns([])))->add($this->organization, ' MAG-Expert.ru ');

    expect($domain->domain)->toBe('mag-expert.ru')
        ->and($domain->status)->toBe(Domain::STATUS_PENDING)
        ->and($domain->verification_token)->toHaveLength(40)
        ->and($domain->dkim_public_key)->not->toBeEmpty()
        ->and($domain->fresh()->dkim_private_key)->toContain('PRIVATE KEY');
});

it('lists the dns records to add', function (): void {
    $service = new DomainService(fakeDns([]));
    $domain = $service->add($this->organization, 'mag-expert.ru');

    $records = collect($service->dnsRecords($domain))->keyBy('key');

    expect($records->keys()->all())->toBe(['verification', 'dkim', 'spf', 'dmarc'])
        ->and($records['verification']['host'])->toBe('_sender-verify.mag-expert.ru')
        ->and($records['verification']['value'])->toBe($domain->verification_token)
        ->and($records['dkim']['host'])->toBe('mail._domainkey.mag-expert.ru');
});

it('verifies a domain when the token is in dns', function (): void {
    $service = new DomainService(fakeDns([]));
    $domain = $service->add($this->organization, 'mag-expert.ru');

    $service = new DomainService(fakeDns([
        '_sender-verify.mag-expert.ru' => [$domain->verification_token],
        'mail._domainkey.mag-expert.ru' => ['v=DKIM1; k=rsa; p='.$domain->dkim_public_key],
    ]));

    $report = $service->verify($domain);

    expect($report)->toBe(['verification' => true, 'dkim' => true, 'spf' => false, 'dmarc' => false])
        ->and($domain->fresh()->status)->toBe(Domain::STATUS_VERIFIED)
        ->and($domain->fresh()->verified_at)->not->toBeNull();
});

it('marks a domain as failed when the token is missing', function (): void {
    $domain = (new DomainService(fakeDns([])))->add($this->organization, 'mag-expert.ru');

    $report = (new DomainService(fakeDns([
        '_sender-verify.mag-expert.ru' => ['wrong-token'],
    ])))->verify($domain);

    expect($report['verification'])->toBeFalse()
        ->and($domain->fresh()->status)->toBe(Domain::STATUS_FAILED)
        ->and($domain->fresh()->verified_at)->toBeNull();
});

it('exports the dkim private key to a file with restricted permissions', function (): void {
    $domain = (new DomainService(fakeDns([])))->add($this->organization, 'mag-expert.ru');
    $path = sys_get_temp_dir().'/dkim-test-'.uniqid().'.key';

    $this->artisan('sender:dkim-export', ['domain' => 'mag-expert.ru', 'path' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toContain('PRIVATE KEY')
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    $this->artisan('sender:dkim-export', ['domain' => 'mag-expert.ru', 'path' => $path])->assertFailed();

    unlink($path);
});

it('imports an existing dkim private key and derives the public key', function (): void {
    $domain = (new DomainService(fakeDns([])))->add($this->organization, 'mag-expert.ru');

    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $pem);
    $path = sys_get_temp_dir().'/dkim-import-'.uniqid().'.key';
    file_put_contents($path, $pem);

    $this->artisan('sender:dkim-import', ['domain' => 'mag-expert.ru', 'path' => $path, '--selector' => 'sel1'])->assertSuccessful();

    $expectedPublic = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($resource)['key']);

    expect($domain->fresh()->dkim_public_key)->toBe($expectedPublic)
        ->and($domain->fresh()->dkim_selector)->toBe('sel1')
        ->and($domain->fresh()->dkim_private_key)->toContain('PRIVATE KEY');

    file_put_contents($path, 'not a key');
    $this->artisan('sender:dkim-import', ['domain' => 'mag-expert.ru', 'path' => $path])->assertFailed();

    unlink($path);
});
