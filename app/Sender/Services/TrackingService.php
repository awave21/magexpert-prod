<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Str;

/**
 * Учёт открытий и кликов: невидимая картинка в письме и ссылки через наш сервер.
 */
class TrackingService
{
    /**
     * Почтовые сканеры и антивирусы открывают ссылки сами, сразу после доставки.
     */
    private const BOT_AGENTS = ['bot', 'crawler', 'spider', 'preview', 'scanner', 'barracuda', 'proofpoint', 'mimecast', 'curl', 'python', 'wget', 'headless', 'go-http', 'java/', 'okhttp', 'kaspersky', 'drweb'];

    private const HUMAN_CLICK_DELAY = 15;

    public function signature(string $uuid, string $url): string
    {
        return substr(hash_hmac('sha256', $uuid.'|'.$url, (string) config('app.key')), 0, 20);
    }

    public function clickUrl(Message $message, string $url): string
    {
        return SenderUi::url('t/c/'.$message->uuid).'?'.http_build_query(['u' => $url, 's' => $this->signature($message->uuid, $url)]);
    }

    public function pixelUrl(Message $message): string
    {
        return SenderUi::url('t/o/'.$message->uuid);
    }

    /**
     * Подменяет ссылки http(s) в письме на ссылки через наш сервер и добавляет картинку учёта открытий.
     * Ссылка отписки и ссылки с переменными, которые не подставились, остаются как есть.
     */
    public function apply(Message $message, string $html, ?string $skipUrl = null): string
    {
        $html = (string) preg_replace_callback(
            '~(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?://[^"\']+)\2~i',
            function (array $m) use ($message, $skipUrl): string {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);

                if ($url === $skipUrl || str_contains($url, '{{')) {
                    return $m[0];
                }

                return $m[1].$m[2].e($this->clickUrl($message, $url)).$m[2];
            },
            $html,
        );

        $pixel = '<img src="'.e($this->pixelUrl($message)).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0" />';

        return str_contains($html, '</body>')
            ? (string) preg_replace('~</body>~i', $pixel.'</body>', $html, 1)
            : $html.$pixel;
    }

    public function recordOpen(Message $message, ?string $ip, ?string $userAgent): void
    {
        // Почта Apple (защита конфиденциальности) загружает картинки сама, с адресов 17.0.0.0/8
        $auto = $ip !== null && str_starts_with($ip, '17.') || $this->isBot($userAgent);

        $this->event($message, MessageEvent::TYPE_OPEN, null, $ip, $userAgent, $auto);

        if (! $auto) {
            $this->markOpened($message);
        }
    }

    public function recordClick(Message $message, string $url, ?string $ip, ?string $userAgent): void
    {
        $tooFast = $message->sent_at !== null && $message->sent_at->diffInSeconds(now()) < self::HUMAN_CLICK_DELAY;
        $auto = $tooFast || $this->isBot($userAgent);

        $this->event($message, MessageEvent::TYPE_CLICK, $url, $ip, $userAgent, $auto);

        if ($auto) {
            return;
        }

        // клик означает, что письмо открыто, даже если картинки были выключены
        $this->markOpened($message, countOpen: $message->opened_at === null);
        $message->forceFill([
            'clicked_at' => $message->clicked_at ?? now(),
            'clicks_count' => $message->clicks_count + 1,
        ])->save();
    }

    private function markOpened(Message $message, bool $countOpen = true): void
    {
        $message->forceFill([
            'opened_at' => $message->opened_at ?? now(),
            'opens_count' => $message->opens_count + ($countOpen ? 1 : 0),
        ])->save();

        Contact::query()
            ->where('organization_id', $message->organization_id)
            ->where('email', $message->to_email)
            ->update(['last_opened_at' => now()]);
    }

    private function isBot(?string $userAgent): bool
    {
        $agent = Str::lower((string) $userAgent);

        return $agent === '' || Str::contains($agent, self::BOT_AGENTS);
    }

    private function event(Message $message, string $type, ?string $detail, ?string $ip, ?string $userAgent, bool $auto): void
    {
        $message->events()->create([
            'type' => $type,
            'detail' => $detail !== null ? Str::limit($detail, 2000, '') : null,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 490, '') : null,
            'is_auto' => $auto,
        ]);
    }
}
