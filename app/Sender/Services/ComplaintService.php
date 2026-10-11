<?php

namespace App\Sender\Services;

use App\Sender\Events\RecipientUnsubscribed;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Suppression;
use App\Sender\Models\Webhook;
use Illuminate\Support\Str;

/**
 * Жалобы «Это спам» из отчётов FBL (формат ARF, RFC 5965). Письмо находится по заголовку X-Sender-Id
 * или по нашему Message-ID в приложенной копии. Адрес блокируется и отписывается от рассылок.
 */
class ComplaintService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * @return Message|null письмо, на которое пожаловались; null — отчёт не о нашем письме
     */
    public function handle(string $raw): ?Message
    {
        $message = $this->find($raw);

        if ($message === null) {
            return null;
        }

        $message->loadMissing('organization');

        if ($message->events()->where('type', MessageEvent::TYPE_COMPLAINT)->exists()) {
            return $message;
        }

        $message->events()->create(['type' => MessageEvent::TYPE_COMPLAINT, 'detail' => $this->feedbackType($raw)]);

        $this->subscriptions->unsubscribe($message->organization, $message->to_email);
        Suppression::query()
            ->where('organization_id', $message->organization_id)
            ->where('email', $message->to_email)
            ->update(['reason' => Suppression::REASON_COMPLAINT]);

        RecipientUnsubscribed::dispatch($message->organization->slug, $message->to_email);
        $this->webhooks->messageEvent($message, Webhook::EVENT_COMPLAINED);

        return $message;
    }

    private function find(string $raw): ?Message
    {
        $host = preg_quote((string) config('sender.mail_host'), '~');
        $uuid = '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';

        foreach ($this->texts($raw) as $text) {
            if (preg_match('~^X-Sender-Id:\s*'.$uuid.'~im', $text, $m) || preg_match('~^Message-ID:\s*<'.$uuid.'@'.$host.'>~im', $text, $m)) {
                $message = Message::query()->where('uuid', Str::lower($m[1]))->first();

                if ($message !== null) {
                    return $message;
                }
            }
        }

        return null;
    }

    private function feedbackType(string $raw): string
    {
        foreach ($this->texts($raw) as $text) {
            if (preg_match('~^Feedback-Type:\s*([a-z-]+)~im', $text, $m)) {
                return Str::lower($m[1]);
            }
        }

        return 'abuse';
    }

    /**
     * Тексты всех частей письма с раскодированным base64 и quoted-printable: копия исходного письма
     * в отчёте бывает закодирована.
     *
     * @return list<string>
     */
    private function texts(string $raw, int $depth = 0): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
        $texts = [$head];

        $encoding = preg_match('~^Content-Transfer-Encoding:\s*(\S+)~im', $head, $m) ? Str::lower($m[1]) : '';
        $body = match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('~\s+~', '', $body), true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        if ($depth < 5 && preg_match('~^Content-Type:\s*multipart/[^;]+;(?:[^\n]|\n[ \t])*?boundary="?([^"\s;]+)"?~im', $head, $m)) {
            foreach (explode('--'.$m[1], $body) as $part) {
                $part = ltrim($part, "\n");
                if ($part !== '' && ! str_starts_with($part, '--')) {
                    array_push($texts, ...$this->texts($part, $depth + 1));
                }
            }

            return $texts;
        }

        if ($depth < 5 && preg_match('~^Content-Type:\s*(message/rfc822|text/rfc822-headers)~im', $head)) {
            array_push($texts, ...$this->texts($body, $depth + 1));

            return $texts;
        }

        $texts[] = $body;

        return $texts;
    }
}
