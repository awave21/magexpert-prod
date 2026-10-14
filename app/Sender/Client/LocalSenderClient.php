<?php

namespace App\Sender\Client;

use App\Sender\Contracts\SenderClient;
use App\Sender\Contracts\SubscriptionClient;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Services\MessageService;
use App\Sender\Services\SubscriptionService;
use RuntimeException;

/**
 * Клиент для случая, когда Sender работает в том же приложении (без HTTP).
 */
class LocalSenderClient implements SenderClient, SubscriptionClient
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function send(string $template, string $to, array $data = []): array
    {
        $organization = $this->organization();

        $templateModel = $organization->templates()->where('slug', $template)->first();

        if ($templateModel === null) {
            throw new RuntimeException("Шаблон Sender '{$template}' не найден");
        }

        $message = $this->messages->send($organization, $templateModel, $to, $data);

        return $this->payload($message);
    }

    public function setSubscription(string $email, bool $subscribed): void
    {
        $subscribed
            ? $this->subscriptions->resubscribe($this->organization(), $email)
            : $this->subscriptions->unsubscribe($this->organization(), $email);
    }

    private function organization(): Organization
    {
        $slug = (string) config('sender.client.organization');

        return Organization::query()->where('slug', $slug)->first()
            ?? throw new RuntimeException("Организация Sender '{$slug}' не найдена");
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
