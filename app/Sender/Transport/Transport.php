<?php

namespace App\Sender\Transport;

use App\Sender\Models\Message;

interface Transport
{
    /**
     * Отправляет собранное письмо. При ошибке выбрасывает исключение.
     *
     * @param  array{subject: string, html: string, text: string|null}  $content
     */
    public function send(Message $message, array $content): void;
}
