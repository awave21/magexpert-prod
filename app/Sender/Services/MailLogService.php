<?php

namespace App\Sender\Services;

use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Suppression;
use Illuminate\Support\Str;

/**
 * Статусы доставки из журнала Postfix (/var/log/mail.log).
 *
 * cleanup связывает номер в очереди Postfix с нашим Message-ID, а smtp сообщает ответ сервера получателя:
 * sent — доставлено, bounced — отказ, deferred — временная ошибка (Postfix повторит), expired — повторы закончились.
 */
class MailLogService
{
    /**
     * Постоянные ошибки адреса: ящика или домена нет. Такие адреса блокируются, чтобы не портить репутацию.
     * Отказы из-за спам-фильтров (5.7.x) адрес не блокируют: проблема в письме или отправителе, а не в ящике.
     */
    private const DEAD_ADDRESS = '~^5\.(1\.[0-9]+|2\.1)$~';

    private const DEAD_TEXT = '~(user unknown|no such user|does not exist|mailbox unavailable|recipient address rejected|unknown user|invalid recipient|account (is )?disabled|not found)~i';

    /**
     * @return bool была ли строка о нашем письме
     */
    public function handle(string $line): bool
    {
        if (preg_match('~postfix/cleanup\[\d+\]: ([A-Za-z0-9]+): message-id=<([0-9a-f-]{36})@~i', $line, $m)) {
            return Message::query()->where('uuid', Str::lower($m[2]))->update(['smtp_queue_id' => $m[1]]) > 0;
        }

        if (preg_match('~postfix/(?:smtp|lmtp|local|virtual|pipe)\[\d+\]: ([A-Za-z0-9]+): to=<([^>]*)>,.*?dsn=([0-9.]+), status=(sent|bounced|deferred) \((.*)\)\s*$~', $line, $m)) {
            return $this->delivery($m[1], Str::lower($m[2]), $m[3], $m[4], $m[5]);
        }

        if (preg_match('~postfix/qmgr\[\d+\]: ([A-Za-z0-9]+): from=<[^>]*>, status=expired~', $line, $m)) {
            $messages = Message::query()->where('smtp_queue_id', $m[1])->whereNotIn('status', [Message::STATUS_DELIVERED, Message::STATUS_BOUNCED])->get();

            foreach ($messages as $message) {
                $message->forceFill(['status' => Message::STATUS_FAILED, 'error' => 'Не удалось доставить: сервер получателя долго не принимал письмо'])->save();
            }

            return $messages->isNotEmpty();
        }

        return false;
    }

    private function delivery(string $queueId, string $to, string $dsn, string $status, string $reply): bool
    {
        $message = Message::query()->where('smtp_queue_id', $queueId)->where('to_email', $to)->first();

        if ($message === null) {
            return false;
        }

        $reply = Str::limit(trim($reply), 1000, '');

        match ($status) {
            'sent' => $this->delivered($message, $reply),
            'bounced' => $this->bounced($message, $dsn, $reply),
            default => $this->deferred($message, $reply),
        };

        return true;
    }

    private function delivered(Message $message, string $reply): void
    {
        $message->forceFill(['status' => Message::STATUS_DELIVERED, 'delivered_at' => now(), 'error' => null])->save();
        $message->events()->create(['type' => MessageEvent::TYPE_DELIVERED, 'detail' => $reply]);
    }

    private function bounced(Message $message, string $dsn, string $reply): void
    {
        $dead = preg_match(self::DEAD_ADDRESS, $dsn) === 1 || preg_match(self::DEAD_TEXT, $reply) === 1;

        $message->forceFill([
            'status' => Message::STATUS_BOUNCED,
            'error' => ($dead ? 'Адреса не существует. ' : 'Сервер получателя отказал. ').'Ответ сервера: '.$reply,
        ])->save();
        $message->events()->create(['type' => MessageEvent::TYPE_BOUNCED, 'detail' => $dsn.' '.$reply]);

        if ($dead) {
            Suppression::query()->firstOrCreate(
                ['organization_id' => $message->organization_id, 'email' => $message->to_email],
                ['reason' => Suppression::REASON_BOUNCE],
            );
        }
    }

    private function deferred(Message $message, string $reply): void
    {
        $message->forceFill(['error' => 'Временная ошибка, Postfix повторит отправку. Ответ сервера: '.$reply])->save();
        $message->events()->create(['type' => MessageEvent::TYPE_DEFERRED, 'detail' => $reply]);
    }
}
