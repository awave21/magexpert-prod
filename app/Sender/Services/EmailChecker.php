<?php

namespace App\Sender\Services;

use App\Sender\Dns\DnsLookup;
use App\Sender\Models\Contact;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Проверка адреса без отправки письма и без платных сервисов: формат, опечатки в домене,
 * одноразовые ящики, общие адреса вроде info@ и наличие почтового сервера у домена (MX).
 *
 * Существует ли сам ящик, так узнать нельзя: это покажет отказ при первой отправке.
 */
class EmailChecker
{
    public const STATUS_OK = 'ok';

    public const STATUS_ROLE = 'role';

    public const STATUS_TYPO = 'typo';

    public const STATUS_DISPOSABLE = 'disposable';

    public const STATUS_NO_MX = 'no_mx';

    public const STATUS_INVALID = 'invalid';

    /**
     * На эти адреса рассылка не отправляется.
     */
    public const UNDELIVERABLE = [self::STATUS_TYPO, self::STATUS_DISPOSABLE, self::STATUS_NO_MX, self::STATUS_INVALID];

    /**
     * Популярные почтовые домены: с ними сравниваем, чтобы найти опечатки.
     */
    private const POPULAR = [
        'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'internet.ru', 'yandex.ru', 'ya.ru', 'yandex.com', 'yandex.by', 'yandex.kz',
        'rambler.ru', 'lenta.ru', 'autorambler.ru', 'myrambler.ru', 'gmail.com', 'googlemail.com', 'icloud.com', 'me.com',
        'mac.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'aol.com', 'proton.me', 'protonmail.com', 'ukr.net',
        'tut.by', 'mail.ua', 'pochta.ru', 'narod.ru',
    ];

    /**
     * Частые ошибки, которые не находятся сравнением по буквам.
     */
    private const KNOWN_TYPOS = ['gmail.ru' => 'gmail.com', 'gmail.co' => 'gmail.com', 'yandex.ri' => 'yandex.ru', 'mail.ri' => 'mail.ru'];

    /**
     * Общие адреса: их читает не один человек, на рассылки по ним чаще жалуются.
     */
    private const ROLE = [
        'admin', 'administrator', 'info', 'information', 'support', 'help', 'helpdesk', 'sales', 'office', 'contact', 'contacts',
        'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'postmaster', 'hostmaster', 'webmaster', 'abuse', 'root', 'mail',
        'mailer-daemon', 'marketing', 'billing', 'accounting', 'hr', 'jobs', 'job', 'career', 'careers', 'press', 'media', 'pr',
        'team', 'service', 'feedback', 'security', 'reception', 'secretary', 'priemnaya', 'buh', 'buhgalteriya', 'kadry', 'zakaz',
        'order', 'orders', 'shop', 'post', 'reklama', 'otdel', 'director', 'manager', 'kanc', 'kancelyariya',
    ];

    /** @var array<string, true>|null */
    private ?array $disposable = null;

    /** @var array<string, bool|null> */
    private array $mailDomains = [];

    public function __construct(private readonly DnsLookup $dns) {}

    /**
     * @return array{status: string, hint: ?string}|null null — DNS не ответил, проверить позже
     */
    public function check(string $email): ?array
    {
        $email = Str::lower(trim($email));

        if (! (new EmailValidator)->isValid($email, new RFCValidation) || ! str_contains($email, '@')) {
            return ['status' => self::STATUS_INVALID, 'hint' => null];
        }

        [$local, $domain] = explode('@', $email, 2);

        if (! str_contains($domain, '.') || str_starts_with($domain, '[')) {
            return ['status' => self::STATUS_INVALID, 'hint' => null];
        }

        $suggestion = $this->suggestDomain($domain);

        // опечатки популярных доменов часто заняты одноразовыми сервисами: подсказка полезнее
        if ($suggestion !== null && $this->isDisposable($domain)) {
            return ['status' => self::STATUS_TYPO, 'hint' => $local.'@'.$suggestion['domain']];
        }

        if ($this->isDisposable($domain)) {
            return ['status' => self::STATUS_DISPOSABLE, 'hint' => null];
        }

        $acceptsMail = $this->acceptsMail($domain);

        // похожий на популярный домен — опечатка, если отличается одной буквой от длинного домена или не принимает почту
        if ($suggestion !== null && ($acceptsMail === false || ($suggestion['distance'] === 1 && strlen($suggestion['domain']) >= 7))) {
            return ['status' => self::STATUS_TYPO, 'hint' => $local.'@'.$suggestion['domain']];
        }

        if ($acceptsMail === null) {
            return null;
        }

        if ($acceptsMail === false) {
            return ['status' => self::STATUS_NO_MX, 'hint' => null];
        }

        if (in_array(Str::before($local, '+'), self::ROLE, true)) {
            return ['status' => self::STATUS_ROLE, 'hint' => null];
        }

        return ['status' => self::STATUS_OK, 'hint' => null];
    }

    /**
     * Проверяет адреса подписчиков и сохраняет результат.
     *
     * @param  iterable<Contact>  $contacts
     */
    public function checkContacts(iterable $contacts): void
    {
        foreach ($contacts as $contact) {
            $result = $this->check($contact->email);

            if ($result === null) {
                continue;
            }

            $contact->forceFill([
                'check_status' => $result['status'],
                'check_hint' => $result['hint'],
                'checked_at' => now(),
            ])->saveQuietly();
        }
    }

    private function isDisposable(string $domain): bool
    {
        if ($this->disposable === null) {
            $lines = file(app_path('Sender/Resources/disposable_domains.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $this->disposable = array_fill_keys(array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '#')), true);
        }

        // поддомены одноразовых сервисов тоже одноразовые
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset($this->disposable[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }

        return false;
    }

    /**
     * @return array{domain: string, distance: int}|null
     */
    private function suggestDomain(string $domain): ?array
    {
        if (in_array($domain, self::POPULAR, true)) {
            return null;
        }

        if (isset(self::KNOWN_TYPOS[$domain])) {
            return ['domain' => self::KNOWN_TYPOS[$domain], 'distance' => 1];
        }

        $best = null;
        foreach (self::POPULAR as $known) {
            $distance = levenshtein($domain, $known);
            $limit = strlen($known) <= 6 ? 1 : 2;

            // пропущенная точка: mailru → mail.ru
            if (str_replace('.', '', $known) === $domain) {
                $distance = 1;
            }

            if ($distance <= $limit && ($best === null || $distance < $best['distance'])) {
                $best = ['domain' => $known, 'distance' => $distance];
            }
        }

        return $best;
    }

    /**
     * true — у домена есть почтовый сервер, false — нет, null — DNS не ответил.
     */
    private function acceptsMail(string $domain): ?bool
    {
        if (in_array($domain, self::POPULAR, true)) {
            return true;
        }

        if (array_key_exists($domain, $this->mailDomains)) {
            return $this->mailDomains[$domain];
        }

        $cached = Cache::get('sender.mx.'.$domain);
        if (is_bool($cached)) {
            return $this->mailDomains[$domain] = $cached;
        }

        $mx = $this->dns->mx($domain);

        if ($mx === null) {
            return $this->mailDomains[$domain] = null;
        }

        // «нулевой» MX (RFC 7505) означает, что домен почту не принимает; без MX почта идёт на A-запись домена
        $accepts = $mx !== [] ? ! ($mx === [''] || $mx === ['.']) : $this->dns->hasAddress($domain);

        Cache::put('sender.mx.'.$domain, $accepts, now()->addDay());

        return $this->mailDomains[$domain] = $accepts;
    }
}
