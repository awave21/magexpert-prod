<?php

namespace App\Sender\Client;

use App\Sender\Contracts\SenderClient;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Services\MessageService;
use RuntimeException;

/**
 * Клиент для случая, когда Sender работает в том же приложении (без HTTP).
 */
class LocalSenderClient implements SenderClient
{
    public function __construct(private readonly MessageService $messages) {}

    public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array
    {
        $slug = (string) config('sender.client.organization');
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            throw new RuntimeException("Организация Sender '{$slug}' не найдена");
        }

        $templateModel = $organization->templates()->where('slug', $template)->first();

        if ($templateModel === null) {
            throw new RuntimeException("Шаблон Sender '{$template}' не найден");
        }

        $message = $this->messages->send($organization, $templateModel, $to, $from, $fromName, $data);

        return $this->payload($message);
    }

    /**
     * @return array{id: string|null, status: string, error: string|null}
     */
    private function payload(Message $message): array
    {
        $message->refresh();

        return ['id' => $message->uuid, 'status' => $message->status, 'error' => $message->error];
    }
}
