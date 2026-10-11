<?php

namespace App\Sender\Dns;

class PhpDnsLookup implements DnsLookup
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_values(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? ''),
            $records,
        ));
    }

    /**
     * @return list<string>|null
     */
    public function mx(string $domain): ?array
    {
        $records = @dns_get_record($domain, DNS_MX);

        if ($records === false) {
            return null;
        }

        usort($records, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_map(static fn (array $record): string => rtrim((string) ($record['target'] ?? ''), '.'), $records));
    }

    public function hasAddress(string $domain): bool
    {
        return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }

    /**
     * @return list<string>|null
     */
    public function ns(string $domain): ?array
    {
        $records = @dns_get_record($domain, DNS_NS);

        if ($records === false) {
            return null;
        }

        return array_values(array_map(static fn (array $record): string => strtolower(rtrim((string) ($record['target'] ?? ''), '.')), $records));
    }
}
