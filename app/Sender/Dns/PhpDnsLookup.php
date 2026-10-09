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
}
