<?php

namespace App\Notifications\Channels;

use App\Services\SenderMailService;
use Illuminate\Notifications\Notification;

/**
 * Канал уведомлений: письмо уходит через Sender по шаблону, который задаёт само уведомление.
 */
class SenderChannel
{
    public function __construct(private readonly SenderMailService $mail) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (method_exists($notification, 'toSender')) {
            $notification->toSender($notifiable, $this->mail);
        }
    }
}
