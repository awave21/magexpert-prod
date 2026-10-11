<?php

namespace App\Sender\Dns;

interface DnsLookup
{
    /**
     * Возвращает значения TXT-записей для имени хоста.
     *
     * @return list<string>
     */
    public function txt(string $host): array;

    /**
     * Почтовые серверы домена (MX), по возрастанию приоритета.
     * Пустой список — у домена нет MX, null — DNS не ответил.
     *
     * @return list<string>|null
     */
    public function mx(string $domain): ?array;

    /**
     * Есть ли у домена A- или AAAA-запись.
     */
    public function hasAddress(string $domain): bool;

    /**
     * Серверы имён домена (NS): по ним понятно, где управляется DNS. null — DNS не ответил.
     *
     * @return list<string>|null
     */
    public function ns(string $domain): ?array;
}
