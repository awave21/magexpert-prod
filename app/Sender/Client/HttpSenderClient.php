<?php

namespace App\Sender\Client;

use App\Sender\Contracts\SenderClient;
use App\Sender\Contracts\SubscriptionClient;
use Illuminate\Support\Facades\Http;

/**
 * Клиент для случая, когда Sender вынесен в отдельный сервис и доступен по HTTP API.
 */
class HttpSenderClient implements SenderClient, SubscriptionClient
{
    public function setSubscription(string $email, bool $subscribed): void
    {
        Http::withToken((string) config('sender.client.api_key'))
            ->acceptJson()
            ->timeout(15)
            ->put(rtrim((string) config('sender.client.url'), '/').'/api/sender/v1/subscriptions', [
                'email' => $email,
                'subscribed' => $subscribed,
            ])
            ->throw();
    }

    public function send(string $template, string $to, array $data = []): array
    {
        $response = Http::withToken((string) config('sender.client.api_key'))
            ->acceptJson()
            ->timeout(15)
            ->post(rtrim((string) config('sender.client.url'), '/').'/api/sender/v1/messages', [
                'template' => $template,
                'to' => $to,
                'data' => $data,
            ]);

        return [
            'id' => $response->json('id'),
            'status' => $response->successful() ? (string) $response->json('status', 'queued') : 'failed',
            'error' => $response->successful() ? null : (string) ($response->json('error') ?? $response->json('message') ?? 'HTTP '.$response->status()),
        ];
    }
}
