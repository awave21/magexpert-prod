<?php

namespace App\Sender\Services;

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\SenderAddress;
use App\Sender\Models\Suppression;
use App\Sender\Models\Template;
use App\Sender\Support\SenderUi;
use Illuminate\Support\Str;

class MessageService
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * Ставит письмо в очередь. Если отправка запрещена, письмо сохраняется со статусом blocked.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        Organization $organization,
        Template $template,
        string $to,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $data = [],
        ?Campaign $campaign = null,
    ): Message {
        $to = Str::lower(trim($to));
        $uuid = (string) Str::uuid();
        $data = array_merge($this->defaults($organization), $data);

        // письма рассылки получают ссылку отписки: её можно вставить в текст как {{ unsubscribe_url }}
        if ($campaign !== null) {
            $data['unsubscribe_url'] = SenderUi::url('unsubscribe/'.$uuid);
        }

        // настройки отправителя в шаблоне главнее значений из запроса: приложению достаточно передать шаблон
        $sender = $template->senderAddress;
        $fromEmail = Str::lower(trim((string) ($sender?->email ?: $fromEmail)));
        $fromName = $sender?->name ?: $fromName;

        $domain = $organization->domains()
            ->where('domain', Str::after($fromEmail, '@'))
            ->first();

        $message = $organization->messages()->make([
            'uuid' => $uuid,
            'domain_id' => $domain?->id,
            'template_id' => $template->id,
            'campaign_id' => $campaign?->id,
            'tracked' => (bool) $campaign?->track,
            'to_email' => $to,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $template->reply_to,
            'subject' => $this->renderer->render($template, $data)['subject'],
            'status' => Message::STATUS_QUEUED,
            'data' => $data,
        ]);

        $blockReason = $this->blockReason($organization, $domain, $to, $fromEmail, $sender);

        if ($blockReason !== null) {
            $message->fill(['status' => Message::STATUS_BLOCKED, 'error' => $blockReason])->save();

            return $message;
        }

        $message->save();

        SendMessageJob::dispatch($message->id)->onQueue(config('sender.queue'));

        return $message;
    }

    /**
     * Значения по умолчанию своих переменных организации: действуют, если приложение не передало значение.
     *
     * @return array<string, string>
     */
    private function defaults(Organization $organization): array
    {
        return $organization->variables()->whereNotNull('default_value')->where('default_value', '!=', '')->pluck('default_value', 'key')->all();
    }

    private function blockReason(Organization $organization, ?Domain $domain, string $to, string $fromEmail, ?SenderAddress $sender = null): ?string
    {
        if ($fromEmail === '') {
            return 'Не указан адрес отправителя: задайте его в настройках шаблона';
        }

        if ($sender !== null && ! $sender->isConfirmed()) {
            return "Адрес отправителя {$sender->email} не подтверждён: откройте письмо со ссылкой в этом ящике";
        }

        if ($domain === null || ! $domain->isVerified()) {
            return 'Домен отправителя не подтверждён';
        }

        $suppressed = Suppression::query()
            ->where('organization_id', $organization->id)
            ->where('email', $to)
            ->exists();

        return $suppressed ? 'Адрес получателя в списке блокировок' : null;
    }
}
