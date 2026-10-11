<?php

namespace App\Sender\Services;

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Domain;
use Illuminate\Support\Str;

/**
 * Подсказки для настройки DNS: смотрит, какие записи уже есть у домена, и для каждой нужной записи
 * говорит, что сделать — добавить, заменить существующую или ничего не трогать.
 * Определяет, где управляется DNS (по NS) и чья почта работает на домене (по MX).
 */
class DnsAdvisor
{
    public const ACTION_OK = 'ok';

    public const ACTION_ADD = 'add';

    public const ACTION_REPLACE = 'replace';

    public const ACTION_KEEP = 'keep';

    public const ACTION_CONFLICT = 'conflict';

    /**
     * Где управляется DNS: по окончанию имени NS-сервера.
     */
    private const DNS_PROVIDERS = [
        'reg.ru' => ['key' => 'regru', 'name' => 'REG.RU', 'url' => 'https://www.reg.ru/user/account/#/domains'],
        'beget.com' => ['key' => 'beget', 'name' => 'Beget', 'url' => 'https://cp.beget.com/dns'],
        'beget.pro' => ['key' => 'beget', 'name' => 'Beget', 'url' => 'https://cp.beget.com/dns'],
        'beget.ru' => ['key' => 'beget', 'name' => 'Beget', 'url' => 'https://cp.beget.com/dns'],
        'yandex.net' => ['key' => 'yandex', 'name' => 'Яндекс 360', 'url' => 'https://admin.yandex.ru/domains'],
        'nic.ru' => ['key' => 'nicru', 'name' => 'RU-CENTER (nic.ru)', 'url' => 'https://www.nic.ru/manager/'],
        'timeweb.ru' => ['key' => 'timeweb', 'name' => 'Timeweb', 'url' => 'https://timeweb.com/my/domains'],
        'timeweb.org' => ['key' => 'timeweb', 'name' => 'Timeweb', 'url' => 'https://timeweb.com/my/domains'],
        'cloudflare.com' => ['key' => 'cloudflare', 'name' => 'Cloudflare', 'url' => 'https://dash.cloudflare.com/'],
        'selectel.org' => ['key' => 'selectel', 'name' => 'Selectel', 'url' => 'https://my.selectel.ru/network/domains'],
        'selectel.ru' => ['key' => 'selectel', 'name' => 'Selectel', 'url' => 'https://my.selectel.ru/network/domains'],
        'sprinthost.ru' => ['key' => 'generic', 'name' => 'SprintHost', 'url' => null],
        'jino.ru' => ['key' => 'generic', 'name' => 'Джино', 'url' => null],
        'hostland.ru' => ['key' => 'generic', 'name' => 'Hostland', 'url' => null],
        'r01.ru' => ['key' => 'generic', 'name' => 'R01', 'url' => null],
    ];

    /**
     * Чья почта работает на домене: по MX. spf — что должно остаться в SPF, dkim — их запись подписи.
     */
    private const MAIL_PROVIDERS = [
        'mail.ru' => ['key' => 'mailru', 'name' => 'Почта Mail.ru для бизнеса (VK WorkSpace)', 'spf' => 'include:_spf.mail.ru', 'dkim' => 'mailru._domainkey'],
        'yandex.net' => ['key' => 'yandex', 'name' => 'Яндекс 360', 'spf' => 'include:_spf.yandex.net', 'dkim' => 'mail._domainkey'],
        'yandex.ru' => ['key' => 'yandex', 'name' => 'Яндекс 360', 'spf' => 'include:_spf.yandex.net', 'dkim' => 'mail._domainkey'],
        'google.com' => ['key' => 'google', 'name' => 'Google Workspace', 'spf' => 'include:_spf.google.com', 'dkim' => 'google._domainkey'],
        'googlemail.com' => ['key' => 'google', 'name' => 'Google Workspace', 'spf' => 'include:_spf.google.com', 'dkim' => 'google._domainkey'],
        'outlook.com' => ['key' => 'microsoft', 'name' => 'Microsoft 365', 'spf' => 'include:spf.protection.outlook.com', 'dkim' => 'selector1._domainkey'],
    ];

    public function __construct(private readonly DnsLookup $dns, private readonly DomainService $domains) {}

    /**
     * @return array{
     *     zone: string,
     *     dns_provider: array{key: string, name: string, url: ?string, ns: list<string>}|null,
     *     mail_provider: array{key: string, name: string, spf: ?string, dkim: ?string, mx: list<string>}|null,
     *     records: list<array<string, mixed>>
     * }
     */
    public function advise(Domain $domain): array
    {
        $mx = $this->dns->mx($domain->domain) ?? [];
        $mail = $this->mailProvider($mx);
        [$zone, $provider] = $this->dnsProvider($domain->domain);
        $records = [];

        foreach ($this->domains->dnsRecords($domain) as $record) {
            $current = $this->dns->txt($record['host']);
            $records[] = [
                ...$record,
                'name' => $this->relativeName($record['host'], $zone),
                'current' => array_values(array_filter($current, fn (string $v): bool => $this->isSameKind($record['key'], $v))),
                ...$this->action($domain, $record, $current, $mail),
            ];
        }

        return [
            'zone' => $zone,
            'dns_provider' => $provider,
            'mail_provider' => $mail,
            'records' => $records,
        ];
    }

    /**
     * @param  array{key: string, type: string, host: string, value: string, required: bool}  $record
     * @param  list<string>  $current
     * @param  array<string, mixed>|null  $mail
     * @return array{action: string, suggested: ?string, note: ?string}
     */
    private function action(Domain $domain, array $record, array $current, ?array $mail): array
    {
        $same = array_values(array_filter($current, fn (string $v): bool => $this->isSameKind($record['key'], $v)));

        return match ($record['key']) {
            'verification' => in_array($domain->verification_token, array_map('trim', $current), true)
                ? $this->result(self::ACTION_OK)
                : $this->result(self::ACTION_ADD),
            'dkim' => $this->dkimAction($domain, $same, $mail),
            'spf' => $this->spfAction($record['value'], $same, $mail),
            'dmarc' => $same === []
                ? $this->result(self::ACTION_ADD)
                : $this->result(self::ACTION_KEEP, null, 'У домена уже есть своя DMARC-запись. Вторую не добавляйте: оставьте существующую как есть.'),
            default => $this->result(self::ACTION_ADD),
        };
    }

    /**
     * @param  list<string>  $same
     * @param  array<string, mixed>|null  $mail
     * @return array{action: string, suggested: ?string, note: ?string}
     */
    private function dkimAction(Domain $domain, array $same, ?array $mail): array
    {
        if ($same === []) {
            return $this->result(self::ACTION_ADD);
        }

        foreach ($same as $value) {
            if (str_contains(str_replace([' ', '"'], '', $value), 'p='.$domain->dkim_public_key)) {
                return $this->result(self::ACTION_OK);
            }
        }

        $owner = $mail !== null && ($mail['dkim'] ?? null) === $domain->dkim_selector.'._domainkey' ? $mail['name'] : null;

        return $this->result(self::ACTION_CONFLICT, null, $owner
            ? "На этом имени уже стоит подпись почты {$owner}. Не удаляйте и не меняйте её — иначе перестанет работать ваша рабочая почта. Напишите администратору Sender: он сменит имя записи для рассылок."
            : 'На этом имени уже есть другая DKIM-запись. Если вы не уверены, чья она, не удаляйте её и напишите администратору Sender.');
    }

    /**
     * Вторая SPF-запись ломает проверку почты, поэтому существующую дополняем, а не дублируем.
     *
     * @param  list<string>  $same
     * @param  array<string, mixed>|null  $mail
     * @return array{action: string, suggested: ?string, note: ?string}
     */
    private function spfAction(string $ours, array $same, ?array $mail): array
    {
        $ourMechanism = trim(Str::between($ours, 'v=spf1', '~all'));

        if ($same === []) {
            $suggested = $mail && $mail['spf'] ? "v=spf1 {$ourMechanism} {$mail['spf']} ~all" : $ours;

            return $this->result(self::ACTION_ADD, $suggested !== $ours ? $suggested : null, $mail && $mail['spf']
                ? "В запись уже включён {$mail['spf']}, чтобы продолжала работать почта {$mail['name']}."
                : null);
        }

        if (count($same) > 1) {
            return $this->result(self::ACTION_CONFLICT, $this->mergeSpf($same, $ourMechanism),
                'У домена несколько SPF-записей, а должна быть одна: из-за этого письма могут попадать в спам. Удалите все записи, которые начинаются с v=spf1, и вместо них добавьте одну — ниже.');
        }

        if ($ourMechanism === '' || str_contains($same[0], $ourMechanism)) {
            return $this->result(self::ACTION_OK);
        }

        return $this->result(self::ACTION_REPLACE, $this->mergeSpf($same, $ourMechanism),
            'SPF-запись у домена уже есть. Не создавайте вторую: отредактируйте существующую и замените её значение на новое. Всё, что было в старой записи, в новой сохранено.');
    }

    /**
     * @param  list<string>  $records
     */
    private function mergeSpf(array $records, string $ourMechanism): string
    {
        $parts = [];
        $all = '~all';

        foreach ($records as $record) {
            foreach (preg_split('/\s+/', trim(str_replace('"', '', $record))) ?: [] as $part) {
                if ($part === '' || Str::lower($part) === 'v=spf1') {
                    continue;
                }
                if (preg_match('/^[-~?+]?all$/i', $part)) {
                    $all = $part;

                    continue;
                }
                $parts[] = $part;
            }
        }

        if ($ourMechanism !== '') {
            array_unshift($parts, $ourMechanism);
        }

        return trim('v=spf1 '.implode(' ', array_unique($parts)).' '.$all);
    }

    /**
     * @return array{action: string, suggested: ?string, note: ?string}
     */
    private function result(string $action, ?string $suggested = null, ?string $note = null): array
    {
        return ['action' => $action, 'suggested' => $suggested, 'note' => $note];
    }

    private function isSameKind(string $key, string $value): bool
    {
        $value = Str::lower(trim(str_replace('"', '', $value)));

        return match ($key) {
            'spf' => str_starts_with($value, 'v=spf1'),
            'dmarc' => str_starts_with($value, 'v=dmarc1'),
            'dkim' => str_contains($value, 'p='),
            default => true,
        };
    }

    /**
     * Имя записи так, как его вводят в большинстве панелей: без имени зоны, @ для самой зоны.
     */
    private function relativeName(string $host, string $zone): string
    {
        return $host === $zone ? '@' : Str::beforeLast($host, '.'.$zone);
    }

    /**
     * Зона, где управляются записи домена (для поддомена обычно родительский домен), и кто её обслуживает.
     *
     * @return array{0: string, 1: array{key: string, name: string, url: ?string, ns: list<string>}|null}
     */
    private function dnsProvider(string $domain): array
    {
        $name = $domain;
        $ns = [];
        while (substr_count($name, '.') >= 1) {
            $ns = $this->dns->ns($name) ?? [];
            if ($ns !== []) {
                break;
            }
            $name = Str::after($name, '.');
        }

        if ($ns === []) {
            return [$domain, null];
        }

        foreach ($ns as $server) {
            foreach (self::DNS_PROVIDERS as $suffix => $provider) {
                if ($server === $suffix || str_ends_with($server, '.'.$suffix)) {
                    return [$name, [...$provider, 'ns' => $ns]];
                }
            }
        }

        return [$name, ['key' => 'generic', 'name' => Str::after($ns[0], '.'), 'url' => null, 'ns' => $ns]];
    }

    /**
     * @param  list<string>  $mx
     * @return array{key: string, name: string, spf: ?string, dkim: ?string, mx: list<string>}|null
     */
    private function mailProvider(array $mx): ?array
    {
        $mx = array_values(array_filter(array_map('strtolower', $mx)));

        foreach ($mx as $server) {
            foreach (self::MAIL_PROVIDERS as $suffix => $provider) {
                if (str_ends_with($server, '.'.$suffix)) {
                    return [...$provider, 'mx' => $mx];
                }
            }
        }

        return $mx === [] ? null : ['key' => 'other', 'name' => $mx[0], 'spf' => null, 'dkim' => null, 'mx' => $mx];
    }
}
