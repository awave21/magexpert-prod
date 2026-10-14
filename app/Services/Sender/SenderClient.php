<?php

namespace App\Services\Sender;

/**
 * Отправка служебных писем сайта через публичный API Sender (POST /messages).
 * Тему и отправителя определяет шаблон в Sender: сайт передаёт только шаблон, получателя и данные.
 */
interface SenderClient
{
    /**
     * @param  array<string, mixed>  $data  переменные шаблона
     * @return array{id: string|null, status: string, error: string|null, http?: int}
     *
     * @throws SenderApiException если API не настроен или Sender недоступен
     */
    public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array;
}
