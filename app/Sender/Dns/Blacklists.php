<?php

namespace App\Sender\Dns;

use Illuminate\Support\Facades\Cache;

/**
 * Проверка IP по DNS-спискам спама (DNSBL). Ответ 127.0.0.x — адрес в списке;
 * 127.255.255.x — список отказал в запросе (например, Spamhaus через публичный DNS), это не попадание.
 */
class Blacklists
{
    /**
     * @param  list<string>  $zones
     * @return list<string> списки, в которых есть IP
     */
    public function listedIn(string $ip, array $zones): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }

        return Cache::remember('sender.blacklists.'.$ip, now()->addHour(), function () use ($ip, $zones): array {
            $reversed = implode('.', array_reverse(explode('.', $ip)));

            return array_values(array_filter($zones, function (string $zone) use ($reversed): bool {
                foreach ($this->resolve($reversed.'.'.$zone) as $answer) {
                    if (str_starts_with($answer, '127.0.0.')) {
                        return true;
                    }
                }

                return false;
            }));
        });
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        return gethostbynamel($host.'.') ?: [];
    }
}
