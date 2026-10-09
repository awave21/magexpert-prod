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
}
