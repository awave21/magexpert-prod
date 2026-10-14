<?php

namespace App\Jobs;

use App\Models\MailOutbox;
use App\Services\Sender\SenderApiException;
use App\Services\Sender\SenderClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Отправляет письмо из исходящих (MailOutbox) в Sender по публичному API.
 *
 * Сбой сети, 429 и 5xx повторяются с нарастающей паузой с тем же Idempotency-Key: второе письмо не создастся.
 * Отказ Sender (нет шаблона, письмо заблокировано, неверные данные) повтором не лечится: письмо помечается failed,
 * а если у записи есть запасной шаблон, уходит он.
 */
class DeliverOutboxMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public int $outboxId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(SenderClient $client): void
    {
        $entry = MailOutbox::query()->find($this->outboxId);

        if ($entry === null || $entry->status !== MailOutbox::STATUS_PENDING) {
            return;
        }

        $entry->increment('attempts');
        $outcome = $this->deliver($client, $entry, $entry->template, $entry->idempotency_key);

        // шаблон мероприятия не принят: стандартное письмо лучше, чем никакого
        if ($outcome === 'rejected' && filled($entry->fallback_template)) {
            Log::warning('Sender: шаблон письма не принят, отправляем запасной', ['outbox_id' => $entry->id, 'template' => $entry->template]);
            $outcome = $this->deliver($client, $entry, (string) $entry->fallback_template, $entry->idempotency_key.':fallback');
        }

        match ($outcome) {
            'sent' => $entry->forceFill([
                'status' => MailOutbox::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
                'next_attempt_at' => null,
                'data' => MailOutbox::redact($entry->data),
            ])->save(),
            'rejected' => $this->markFailed($entry),
            default => $this->retryLater($entry),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $entry = MailOutbox::query()->find($this->outboxId);

        if ($entry !== null && $entry->status === MailOutbox::STATUS_PENDING) {
            $entry->last_error = $exception?->getMessage() ?? $entry->last_error;
            $this->markFailed($entry);
        }
    }

    /**
     * @return 'sent'|'rejected'|'retry'
     */
    private function deliver(SenderClient $client, MailOutbox $entry, string $template, string $key): string
    {
        try {
            $result = $client->send($template, $entry->to_email, (array) $entry->data, $key, $entry->uuid);
        } catch (SenderApiException $exception) {
            $entry->last_error = $exception->getMessage();

            return $exception->retryable ? 'retry' : 'rejected';
        } catch (Throwable $exception) {
            $entry->last_error = $exception->getMessage();

            return 'retry';
        }

        if (in_array($result['status'], ['queued', 'sending', 'sent'], true)) {
            $entry->sender_message_id = $result['id'];

            return 'sent';
        }

        $entry->last_error = (string) ($result['error'] ?? $result['status']);
        $http = (int) ($result['http'] ?? 0);

        return $http === 429 || $http >= 500 ? 'retry' : 'rejected';
    }

    private function markFailed(MailOutbox $entry): void
    {
        $entry->forceFill([
            'status' => MailOutbox::STATUS_FAILED,
            'next_attempt_at' => null,
            'data' => MailOutbox::redact($entry->data),
        ])->save();

        Log::warning('Sender: письмо не отправлено', ['outbox_id' => $entry->id, 'uuid' => $entry->uuid, 'template' => $entry->template, 'error' => $entry->last_error]);
    }

    private function retryLater(MailOutbox $entry): void
    {
        $entry->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save();

        throw new RuntimeException('Sender недоступен: '.$entry->last_error);
    }
}
