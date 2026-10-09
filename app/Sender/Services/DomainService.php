<?php

namespace App\Sender\Services;

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use Illuminate\Support\Str;
use RuntimeException;

class DomainService
{
    public function __construct(private readonly DnsLookup $dns) {}

    public function add(Organization $organization, string $domain): Domain
    {
        $domain = Str::lower(trim($domain));

        $keys = $this->generateDkimKeys();

        return $organization->domains()->create([
            'domain' => $domain,
            'verification_token' => Str::random(40),
            'status' => Domain::STATUS_PENDING,
            'dkim_selector' => config('sender.dkim_selector'),
            'dkim_private_key' => $keys['private'],
            'dkim_public_key' => $keys['public'],
        ]);
    }

    /**
     * DNS-записи, которые нужно добавить для домена.
     *
     * @return list<array{key: string, type: string, host: string, value: string, required: bool}>
     */
    public function dnsRecords(Domain $domain): array
    {
        $serverIp = config('sender.server_ip');

        return [
            [
                'key' => 'verification',
                'type' => 'TXT',
                'host' => '_sender-verify.'.$domain->domain,
                'value' => $domain->verification_token,
                'required' => true,
            ],
            [
                'key' => 'dkim',
                'type' => 'TXT',
                'host' => $domain->dkim_selector.'._domainkey.'.$domain->domain,
                'value' => 'v=DKIM1; k=rsa; p='.$domain->dkim_public_key,
                'required' => true,
            ],
            [
                'key' => 'spf',
                'type' => 'TXT',
                'host' => $domain->domain,
                'value' => $serverIp ? "v=spf1 ip4:{$serverIp} ~all" : 'v=spf1 ~all',
                'required' => false,
            ],
            [
                'key' => 'dmarc',
                'type' => 'TXT',
                'host' => '_dmarc.'.$domain->domain,
                'value' => 'v=DMARC1; p=none',
                'required' => false,
            ],
        ];
    }

    /**
     * Проверяет DNS и обновляет статус домена.
     *
     * @return array<string, bool> Результат по каждой записи (ключи как в dnsRecords).
     */
    public function verify(Domain $domain): array
    {
        $serverIp = config('sender.server_ip');

        $report = [
            'verification' => $this->txtContains(
                '_sender-verify.'.$domain->domain,
                fn (string $value): bool => trim($value) === $domain->verification_token,
            ),
            'dkim' => $this->txtContains(
                $domain->dkim_selector.'._domainkey.'.$domain->domain,
                fn (string $value): bool => str_contains($value, 'v=DKIM1')
                    && str_contains(str_replace(' ', '', $value), 'p='.$domain->dkim_public_key),
            ),
            'spf' => $this->txtContains(
                $domain->domain,
                fn (string $value): bool => str_starts_with($value, 'v=spf1')
                    && ($serverIp === null || $serverIp === '' || str_contains($value, "ip4:{$serverIp}") || str_contains($value, 'include:')),
            ),
            'dmarc' => $this->txtContains(
                '_dmarc.'.$domain->domain,
                fn (string $value): bool => str_starts_with($value, 'v=DMARC1'),
            ),
        ];

        $domain->forceFill([
            'status' => $report['verification'] ? Domain::STATUS_VERIFIED : Domain::STATUS_FAILED,
            'last_checked_at' => now(),
            'verified_at' => $report['verification'] ? ($domain->verified_at ?? now()) : null,
        ])->save();

        return $report;
    }

    private function txtContains(string $host, callable $matches): bool
    {
        foreach ($this->dns->txt($host) as $value) {
            if ($matches($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{private: string, public: string}
     */
    private function generateDkimKeys(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($resource === false || ! openssl_pkey_export($resource, $private)) {
            throw new RuntimeException('Не удалось сгенерировать ключи DKIM');
        }

        $details = openssl_pkey_get_details($resource);

        $public = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $details['key']);

        return ['private' => $private, 'public' => $public];
    }
}
