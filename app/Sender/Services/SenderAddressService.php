<?php

namespace App\Sender\Services;

use App\Sender\Mail\SenderAddressConfirmationMail;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\SenderAddress;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Адреса отправителей: добавление и подтверждение владения ящиком по ссылке из письма.
 */
class SenderAddressService
{
    public const LINK_TTL_HOURS = 48;

    public const RESEND_AFTER_SECONDS = 60;

    /**
     * Адрес сохраняется, даже если письмо со ссылкой не ушло: его можно отправить ещё раз из списка.
     *
     * @return array{address: SenderAddress, sent: bool, error: ?string}
     */
    public function create(Organization $organization, Domain $domain, string $email, string $name): array
    {
        $address = $organization->senderAddresses()->create([
            'domain_id' => $domain->id,
            'email' => Str::lower(trim($email)),
            'name' => trim($name),
        ]);

        $error = $this->trySendConfirmation($address);

        return ['address' => $address, 'sent' => $error === null, 'error' => $error];
    }

    /**
     * @return string|null текст ошибки, если письмо не отправилось
     */
    public function trySendConfirmation(SenderAddress $address): ?string
    {
        try {
            $this->sendConfirmation($address);

            return null;
        } catch (Throwable $exception) {
            Log::error('Sender: не удалось отправить письмо подтверждения адреса', ['email' => $address->email, 'error' => $exception->getMessage()]);

            return $exception->getMessage();
        }
    }

    public function canResend(SenderAddress $address): bool
    {
        return $address->confirmation_sent_at === null
            || $address->confirmation_sent_at->lt(now()->subSeconds(self::RESEND_AFTER_SECONDS));
    }

    public function sendConfirmation(SenderAddress $address): void
    {
        $plain = Str::random(48);
        $url = SenderUi::url('confirm-address/'.$plain);

        $address->forceFill(['confirmation_token' => hash('sha256', $plain)])->save();
        Mail::to($address->email)->send(new SenderAddressConfirmationMail($address, $url, $this->letterHtml($address, $url)));

        // время отправки — только после успешной отправки, иначе «Отправить ещё раз» заблокируется на минуту
        $address->forceFill(['confirmation_sent_at' => now()])->save();
    }

    /**
     * Подтверждает адрес по ссылке из письма. Возвращает адрес или null, если ссылка неверна или устарела.
     */
    public function confirm(string $plain): ?SenderAddress
    {
        $address = SenderAddress::query()->where('confirmation_token', hash('sha256', $plain))->first();

        if ($address === null || $address->confirmation_sent_at?->lt(now()->subHours(self::LINK_TTL_HOURS))) {
            return null;
        }

        $address->forceFill(['confirmed_at' => now(), 'confirmation_token' => null])->save();

        return $address;
    }

    private function letterHtml(SenderAddress $address, string $url): string
    {
        $email = e($address->email);
        $org = e((string) $address->organization?->name);
        $link = e($url);

        return <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#EEF1F6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F6"><tr><td align="center" style="padding:32px 12px">
<table role="presentation" width="520" cellpadding="0" cellspacing="0" style="width:520px;max-width:100%;background:#fff;border-radius:12px">
<tr><td style="padding:32px 36px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1C1F27">
<p style="margin:0 0 16px;font-size:20px;font-weight:700">Подтвердите адрес отправителя</p>
<p style="margin:0 0 16px">Адрес <b>{$email}</b> добавлен как отправитель писем в Sender организации «{$org}».</p>
<p style="margin:0 0 24px">Нажмите кнопку, чтобы подтвердить, что ящик ваш. Ссылка действует 48 часов.</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#4C68EB" style="border-radius:10px">
<a href="{$link}" target="_blank" style="display:inline-block;padding:14px 28px;color:#fff;font-weight:600;text-decoration:none">Подтвердить адрес</a>
</td></tr></table>
<p style="margin:24px 0 0;font-size:13px;color:#636976">Если вы ничего не добавляли, просто удалите это письмо: без подтверждения с адреса ничего не отправится.</p>
</td></tr></table>
</td></tr></table>
</body></html>
HTML;
    }
}
