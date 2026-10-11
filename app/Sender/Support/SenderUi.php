<?php

namespace App\Sender\Support;

/**
 * Адрес админ-интерфейса Sender: свой поддомен (SENDER_UI_URL, например https://mail.mag-expert.ru)
 * или, если он не задан, путь /sender на домене приложения.
 */
class SenderUi
{
    public static function host(): ?string
    {
        $url = config('sender.ui_url');

        return $url ? parse_url((string) $url, PHP_URL_HOST) : null;
    }

    public static function url(string $path = ''): string
    {
        $base = config('sender.ui_url');
        $path = ltrim($path, '/');

        return $base ? rtrim((string) $base, '/').'/'.$path : url('/sender/'.$path);
    }

    public static function indexResponse(): mixed
    {
        $index = public_path('sender-static/index.html');

        abort_unless(is_file($index), 404, 'Интерфейс Sender не собран: npm --prefix sender-ui run build');

        return response()->file($index, ['Cache-Control' => 'no-cache']);
    }
}
