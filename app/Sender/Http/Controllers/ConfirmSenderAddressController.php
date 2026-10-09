<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Services\SenderAddressService;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ConfirmSenderAddressController extends Controller
{
    public function __invoke(string $token, SenderAddressService $addresses): Response
    {
        $address = $addresses->confirm($token);

        [$title, $text, $ok] = $address
            ? ['Адрес подтверждён', 'Теперь с адреса '.e($address->email).' можно отправлять письма. Эту вкладку можно закрыть.', true]
            : ['Ссылка не действует', 'Возможно, она устарела или адрес уже подтверждён. Отправьте письмо ещё раз из раздела «Домены» в Sender.', false];

        $color = $ok ? '#147F0A' : '#A92321';
        $icon = $ok ? '✓' : '!';
        $panel = e(url('/sender/domains'));

        $html = <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title} · Sender</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F7FB;font:15px/1.6 system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#1C1F27;padding:16px;box-sizing:border-box}
.card{max-width:440px;background:#fff;border-radius:16px;padding:36px;box-shadow:0 1px 3px rgba(28,31,39,.08),0 12px 32px -12px rgba(28,31,39,.18);text-align:center}
.ic{width:52px;height:52px;border-radius:50%;margin:0 auto 16px;display:grid;place-items:center;color:#fff;font-size:24px;font-weight:700;background:{$color}}
h1{margin:0 0 8px;font-size:22px;letter-spacing:-.02em}p{margin:0 0 20px;color:#4A505B}a{color:#3B51D3;font-weight:500}</style></head>
<body><div class="card"><div class="ic">{$icon}</div><h1>{$title}</h1><p>{$text}</p><a href="{$panel}">Открыть Sender</a></div></body></html>
HTML;

        return response($html, $ok ? 200 : 410)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
