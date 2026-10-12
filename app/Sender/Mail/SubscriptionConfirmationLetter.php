<?php

namespace App\Sender\Mail;

/**
 * Письмо с просьбой подтвердить подписку: его получает адрес, загруженный в базу без согласия.
 * Текст собран в коде, а не в шаблоне организации: без счётчика открытий, учёта переходов и ссылки отписки.
 */
class SubscriptionConfirmationLetter
{
    /**
     * Метка в данных письма, по которой задача отправки собирает это письмо.
     */
    public const KIND = 'subscription_confirmation';

    public static function subject(string $organization): string
    {
        return 'Подтвердите подписку на рассылку '.$organization;
    }

    /**
     * @param  array<string, mixed>  $data  confirm_url, organization_name, name
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $data): array
    {
        $organization = (string) ($data['organization_name'] ?? '');
        $url = (string) ($data['confirm_url'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        $greeting = $name !== '' ? 'Здравствуйте, '.$name.'!' : 'Здравствуйте!';

        $intro = 'Ваш адрес добавили в рассылку '.$organization.'. Чтобы получать письма, подтвердите подписку.';
        $note = 'Если вы не подписывались, ничего делать не нужно: без подтверждения письма рассылки приходить не будут.';

        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$e(self::subject($organization))}</title></head>
<body style="margin:0;padding:0;background:#F5F7FB">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FB"><tr><td align="center" style="padding:32px 16px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#FFFFFF;border-radius:12px">
<tr><td style="padding:32px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1C1F27">
<p style="margin:0 0 12px">{$e($greeting)}</p>
<p style="margin:0 0 24px">{$e($intro)}</p>
<p style="margin:0 0 24px"><a href="{$e($url)}" style="display:inline-block;padding:12px 28px;background:#1C1F27;color:#FFFFFF;text-decoration:none;font-weight:bold;border-radius:8px">Подтвердить подписку</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#6B7280">Если кнопка не открывается, скопируйте ссылку в браузер:<br><a href="{$e($url)}" style="color:#6B7280;word-break:break-all">{$e($url)}</a></p>
<p style="margin:16px 0 0;font-size:13px;color:#6B7280">{$e($note)}</p>
</td></tr></table>
</td></tr></table>
</body></html>
HTML;

        $text = $greeting."\n\n".$intro."\n\nПодтвердить подписку: ".$url."\n\n".$note;

        return ['subject' => self::subject($organization), 'html' => $html, 'text' => $text];
    }
}
