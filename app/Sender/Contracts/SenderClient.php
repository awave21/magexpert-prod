<?php

namespace App\Sender\Contracts;

interface SenderClient
{
    /**
     * Отправляет письмо по шаблону. Возвращает ответ сервиса рассылок.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string|null, status: string, error: string|null}
     */
    public function send(string $template, string $to, string $from, ?string $fromName = null, array $data = []): array;
}
