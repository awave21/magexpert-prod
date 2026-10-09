<?php

namespace App\Sender\Jobs;

use App\Sender\Models\Message;
use App\Sender\Services\TemplateRenderer;
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

    public function handle(TemplateRenderer $renderer, Transport $transport): void
    {
        $message = Message::query()->with('template')->findOrFail($this->messageId);

        if ($message->status === Message::STATUS_SENT || $message->template === null) {
            return;
        }

        $message->forceFill([
            'status' => Message::STATUS_SENDING,
            'attempts' => $message->attempts + 1,
        ])->save();

        $transport->send($message, $this->withUnsubscribeLink($message, $renderer->render($message->template, $message->data ?? [])));

        $message->forceFill([
            'status' => Message::STATUS_SENT,
            'error' => null,
            'sent_at' => now(),
        ])->save();
    }

    /**
     * В письмо рассылки без ссылки отписки она добавляется внизу автоматически.
     *
     * @param  array{subject: string, html: string, text?: string|null}  $content
     * @return array{subject: string, html: string, text?: string|null}
     */
    private function withUnsubscribeLink(Message $message, array $content): array
    {
        $url = $message->campaign_id ? ($message->data['unsubscribe_url'] ?? null) : null;

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
