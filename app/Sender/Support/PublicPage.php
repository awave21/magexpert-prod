<?php

namespace App\Sender\Support;

use Illuminate\Http\Response;

/**
 * Простая страница для подписчика по ссылке из письма: отписка, подтверждение подписки.
 * $title и $text — уже экранированный HTML, $ok выбирает значок: true — готово, false — ошибка, null — вопрос.
 */
class PublicPage
{
    public static function render(string $title, string $text, ?bool $ok, int $status = 200, string $extra = ''): Response
    {
        $color = match ($ok) {
            true => '#147F0A', false => '#A92321', null => '#F27B63'
        };
        $icon = match ($ok) {
            true => '✓', false => '!', null => '✉'
        };

        $policy = e(SenderUi::url('privacy'));

        $html = <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>{$title}</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F7FB;font:15px/1.6 system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#1C1F27;padding:16px;box-sizing:border-box}
.card{max-width:440px;background:#fff;border-radius:16px;padding:36px;box-shadow:0 1px 3px rgba(28,31,39,.08),0 12px 32px -12px rgba(28,31,39,.18);text-align:center}
.ic{width:52px;height:52px;border-radius:50%;margin:0 auto 16px;display:grid;place-items:center;color:#fff;font-size:24px;font-weight:700;background:{$color}}
h1{margin:0 0 8px;font-size:22px;letter-spacing:-.02em}p{margin:0 0 20px;color:#4A505B}
.policy{margin:24px 0 0;font-size:13px}.policy a{color:#6B7280}
button{font:inherit;font-weight:600;border:0;border-radius:10px;padding:12px 28px;background:#1C1F27;color:#fff;cursor:pointer}button:focus-visible{outline:3px solid #4C68EB;outline-offset:2px}</style></head>
<body><div class="card"><div class="ic">{$icon}</div><h1>{$title}</h1><p>{$text}</p>{$extra}<p class="policy"><a href="{$policy}">Политика обработки персональных данных</a></p></div></body></html>
HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
