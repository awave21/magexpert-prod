<?php

namespace App\Sender\Support;

/**
 * Ведёт ли адрес только в публичный интернет. Вебхуки не должны ходить на localhost, во внутреннюю сеть
 * VPS и на служебные адреса облака: иначе через Sender можно стучаться во внутренние сервисы (SSRF).
 */
class PublicAddress
{
    public function allows(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->addresses($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Все адреса имени (A и AAAA): если хоть один внутренний, адрес не подходит.
     *
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
