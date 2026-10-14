<?php

namespace App\Sender\Contracts;

interface SenderClient
{
    /**
     * Отправляет письмо по шаблону. Возвращает ответ сервиса рассылок.
     * Тему и отправителя определяет сам шаблон в Sender: приложению достаточно передать шаблон, получателя и данные.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string|null, status: string, error: string|null}
     */
    public function send(string $template, string $to, array $data = []): array;
}
