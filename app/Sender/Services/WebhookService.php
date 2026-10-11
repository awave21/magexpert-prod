<?php

namespace App\Sender\Services;

use App\Sender\Jobs\SendWebhookJob;
use App\Sender\Models\Message;
use App\Sender\Models\Webhook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * События писем для приложений организации: ставит в очередь отправку на её вебхуки.
 */
class WebhookService
{
    /**
     * @param  array<string, mixed>  $data  подробности события (ответ сервера, ссылка клика)
     */
    public function messageEvent(Message $message, string $event, array $data = []): void
    {
        $this->dispatch($message->organization_id, $event, [
            'message' => [
                'id' => $message->uuid,
                'to' => $message->to_email,
                'subject' => $message->subject,
                'status' => $message->status,
                'campaign_id' => $message->campaign_id,
            ],
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(int $organizationId, string $event, array $data): void
    {
        foreach ($this->active($organizationId) as $webhook) {
            if ($webhook->wants($event)) {
                SendWebhookJob::dispatch($webhook->id, ['event' => $event, 'occurred_at' => now()->toIso8601String(), ...$data])
                    ->onQueue(config('sender.queue'));
            }
        }
    }

    public function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function forget(int $organizationId): void
    {
        Cache::forget($this->cacheKey($organizationId));
    }

    /**
     * Вебхуки организации кэшируются на минуту: разбор журнала Postfix порождает тысячи событий.
     *
     * @return Collection<int, Webhook>
     */
    private function active(int $organizationId): Collection
    {
        return Cache::remember($this->cacheKey($organizationId), 60, fn (): Collection => Webhook::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get());
    }

    private function cacheKey(int $organizationId): string
    {
        return 'sender.webhooks.'.$organizationId;
    }
}
