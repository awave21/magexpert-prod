<?php

namespace App\Sender\Jobs;

use App\Sender\Models\Webhook;
use App\Sender\Services\WebhookService;
use App\Sender\Support\PublicAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * POST события на вебхук. Заголовок X-Sender-Signature: sha256=HMAC тела секретом вебхука.
 * Ответ не 2xx — повтор через 1, 5, 15 и 60 минут.
 */
class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $webhookId, public array $payload) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(WebhookService $webhooks): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        // адрес проверяется и перед отправкой: DNS имени могли поменять на внутренний адрес после сохранения
        if (! app(PublicAddress::class)->allows($webhook->url)) {
            $this->record($webhook, null, 'Адрес ведёт во внутреннюю сеть: отправка запрещена');

            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Sender-Event' => (string) $this->payload['event'],
                    'X-Sender-Signature' => $webhooks->signature($body, $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (Throwable $exception) {
            $this->record($webhook, null, $exception->getMessage());

            throw $exception;
        }

        if (! $response->successful()) {
            $this->record($webhook, $response->status(), 'Ответ '.$response->status());

            throw new RuntimeException("Вебхук {$webhook->url} ответил {$response->status()}");
        }

        $this->record($webhook, $response->status(), null);
    }

    private function record(Webhook $webhook, ?int $status, ?string $error): void
    {
        $webhook->forceFill([
            'last_status' => $status,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'last_sent_at' => now(),
        ])->save();
    }
}
