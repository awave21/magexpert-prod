<?php

namespace App\Sender\Client;

use App\Sender\Contracts\SenderClient;
use Illuminate\Support\Facades\Http;

/**
 * Клиент для случая, когда Sender вынесен в отдельный сервис и доступен по HTTP API.
 */
class HttpSenderClient implements SenderClient
{
    public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
    {
        $response = Http::withToken((string) config('sender.client.api_key'))
            ->acceptJson()
            ->timeout(15)
            ->post(rtrim((string) config('sender.client.url'), '/').'/api/sender/v1/messages', [
                'template' => $template,
                'to' => $to,
                'from' => $from,
                'from_name' => $fromName,
                'data' => $data,
            ]);

        return [
            'id' => $response->json('id'),
            'status' => $response->successful() ? (string) $response->json('status', 'queued') : 'failed',
            'error' => $response->successful() ? null : (string) ($response->json('error') ?? $response->json('message') ?? 'HTTP '.$response->status()),
        ];
    }
}
