<?php

namespace App\Sender\Jobs;

use App\Sender\Mail\SubscriptionConfirmationLetter;
use App\Sender\Models\Campaign;
use App\Sender\Models\Message;
use App\Sender\Services\TemplateRenderer;
use App\Sender\Services\TrackingService;
use App\Sender\Transport\Transport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
        $this->tries = (int) config('sender.max_attempts', 3);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TemplateRenderer $renderer, Transport $transport, ?TrackingService $tracking = null): void
    {
        $message = Message::query()->with(['template', 'campaign'])->findOrFail($this->messageId);

        if (in_array($message->status, [...Message::SENT_STATUSES, Message::STATUS_BOUNCED, Message::STATUS_CANCELLED, Message::STATUS_BLOCKED, Message::STATUS_PENDING], true)) {
            return;
        }

        // письма Sender без шаблона (подтверждение подписки) собираются в коде
        $system = $message->template === null ? ($message->data['system'] ?? null) : null;

        if ($message->template === null && $system !== SubscriptionConfirmationLetter::KIND) {
            return;
        }

        if ($this->stoppedByCampaign($message)) {
            return;
        }

        $letter = null;

        if ($system === SubscriptionConfirmationLetter::KIND) {
            $letter = SubscriptionConfirmationLetter::withConfirmUrl($message->data ?? []);

            if ($letter === null) {
                Message::query()->whereKey($message->id)->whereIn('status', [Message::STATUS_QUEUED, Message::STATUS_FAILED])
                    ->update(['status' => Message::STATUS_CANCELLED, 'error' => 'Подписчика уже нет в базе', 'updated_at' => now()]);

                return;
            }
        }

        // письмо забирает одна задача: дубль задачи (повтор очереди, восстановление зависших) его не отправит второй раз
        $claimed = Message::query()->whereKey($message->id)
            ->whereIn('status', [Message::STATUS_QUEUED, Message::STATUS_FAILED])
            ->update(['status' => Message::STATUS_SENDING, 'attempts' => $message->attempts + 1, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        // любая ошибка после захвата (шаблон, трекинг, Postfix) возвращает письмо в очередь: иначе оно навсегда
        // осталось бы «отправляется», повтор задачи не смог бы его забрать, а failed() не сработал бы
        try {
            $content = $letter !== null
                ? SubscriptionConfirmationLetter::render($letter)
                : $this->withUnsubscribeLink($message, $renderer->render($message->template, $message->data ?? []));

            if ($message->data['campaign_test'] ?? false) {
                $content['subject'] = 'Тест: '.$content['subject'];
            }

            if ($message->tracked) {
                $content['html'] = ($tracking ?? app(TrackingService::class))->apply($message, $content['html'], $message->data['unsubscribe_url'] ?? null);
            }

            $transport->send($message, $content);
        } catch (Throwable $exception) {
            Message::query()->whereKey($message->id)->update([
                'status' => Message::STATUS_QUEUED,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }

        $message->forceFill([
            'status' => Message::STATUS_SENT,
            'error' => null,
            'sent_at' => now(),
            'data' => Message::redact($message->data),
        ])->save();
    }

    /**
     * Рассылку поставили на паузу или отменили, пока письмо стояло в очереди.
     * На паузе письмо возвращается ждать своей пачки, при отмене не уходит совсем.
     */
    private function stoppedByCampaign(Message $message): bool
    {
        $status = $message->campaign?->status;

        if ($status === Campaign::STATUS_PAUSED) {
            $message->forceFill(['status' => Message::STATUS_PENDING, 'dispatched_at' => null])->save();

            return true;
        }

        if ($status === Campaign::STATUS_CANCELLED) {
            $message->forceFill(['status' => Message::STATUS_CANCELLED, 'error' => 'Рассылка отменена'])->save();

            return true;
        }

        return false;
    }

    /**
     * В любое письмо без ссылки отписки она добавляется внизу автоматически: убрать её из шаблона нельзя.
     *
     * @param  array{subject: string, html: string, text?: string|null}  $content
     * @return array{subject: string, html: string, text?: string|null}
     */
    private function withUnsubscribeLink(Message $message, array $content): array
    {
        $url = $message->data['unsubscribe_url'] ?? null;

        if (! is_string($url) || str_contains($content['html'], $url)) {
            return $content;
        }

        $link = '<div style="text-align:center;font:13px/1.5 Arial,Helvetica,sans-serif;color:#6B7280;padding:16px 16px 24px">'
            .'Чтобы не получать такие письма, <a href="'.e($url).'" style="color:#6B7280;text-decoration:underline">отпишитесь от рассылки</a>.</div>';

        $content['html'] = str_contains($content['html'], '</body>')
            ? (string) preg_replace('~</body>~i', $link.'</body>', $content['html'], 1)
            : $content['html'].$link;
        $content['text'] = trim(($content['text'] ?? '')."\n\nОтписаться от рассылки: ".$url);

        return $content;
    }

    public function failed(Throwable $exception): void
    {
        Message::query()->whereKey($this->messageId)->update([
            'status' => Message::STATUS_FAILED,
            'error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
