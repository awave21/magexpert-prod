<?php

namespace App\Sender\Services;

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\Template;
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
        string $fromEmail,
        ?string $fromName = null,
        array $data = [],
    ): Message {
        $to = Str::lower(trim($to));
        $fromEmail = Str::lower(trim($fromEmail));

        $domain = $organization->domains()
            ->where('domain', Str::after($fromEmail, '@'))
            ->first();

        $message = $organization->messages()->make([
            'uuid' => (string) Str::uuid(),
            'domain_id' => $domain?->id,
            'template_id' => $template->id,
            'to_email' => $to,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $this->renderer->render($template, $data)['subject'],
            'status' => Message::STATUS_QUEUED,
            'data' => $data,
        ]);

        $blockReason = $this->blockReason($organization, $domain, $to);

        if ($blockReason !== null) {
            $message->fill(['status' => Message::STATUS_BLOCKED, 'error' => $blockReason])->save();

            return $message;
        }

        $message->save();

        SendMessageJob::dispatch($message->id)->onQueue(config('sender.queue'));

        return $message;
    }

    private function blockReason(Organization $organization, ?Domain $domain, string $to): ?string
    {
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
