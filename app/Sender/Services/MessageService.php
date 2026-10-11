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
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly CampaignSchedule $schedule,
    ) {}

    /**
     * Ставит письмо в очередь. Если отправка запрещена, письмо сохраняется со статусом blocked.
     * Служебные письма идут в свою очередь, чтобы не ждать рассылок.
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

        [$fromEmail, $fromName, $domain, $sender] = $this->sender($organization, $template, $fromEmail, $fromName);

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
            'subject' => $this->renderer->renderSubject($template, $data),
            'status' => Message::STATUS_QUEUED,
            'data' => $data,
        ]);

        $blockReason = $this->senderBlockReason($domain, $fromEmail, $sender)
            ?? $this->limitReason($organization)
            ?? $this->suppressionReason(Suppression::query()
                ->where('organization_id', $organization->id)
                ->where('email', $to)
                ->value('reason'), $campaign !== null);

        if ($blockReason !== null) {
            $message->fill(['status' => Message::STATUS_BLOCKED, 'error' => $blockReason])->save();

            return $message;
        }

        if ($campaign !== null) {
            $message->dispatched_at = now();
        }

        $message->save();

        $this->dispatch($message);

        return $message;
    }

    /**
     * Готовит письма рассылки пачкой, не отправляя их: статус pending, в очередь их ставит sender:campaigns-tick.
     * Проверки отправителя делаются один раз, блок-лист — одним запросом на пачку.
     * Адреса, которым письмо этой рассылки уже подготовлено, пропускаются (уникальный индекс).
     *
     * @param  array<string, array<string, mixed>>  $recipients  email => переменные подписчика
     * @return int сколько писем добавлено
     */
    public function prepareCampaignMessages(Campaign $campaign, array $recipients): int
    {
        if ($recipients === []) {
            return 0;
        }

        $organization = $campaign->organization;
        $template = $campaign->template;
        $defaults = $this->defaults($organization);

        [$fromEmail, $fromName, $domain, $sender] = $this->sender($organization, $template, null, null);
        $senderReason = $this->senderBlockReason($domain, $fromEmail, $sender);

        $suppressed = Suppression::query()
            ->where('organization_id', $organization->id)
            ->whereIn('email', array_keys($recipients))
            ->pluck('reason', 'email');

        $now = now();
        $rows = [];

        foreach ($recipients as $email => $variables) {
            $uuid = (string) Str::uuid();
            $data = array_merge($defaults, $variables, ['unsubscribe_url' => SenderUi::url('unsubscribe/'.$uuid)]);
            $reason = $senderReason ?? $this->suppressionReason($suppressed[$email] ?? null, true);

            $rows[] = [
                'uuid' => $uuid,
                'organization_id' => $organization->id,
                'domain_id' => $domain?->id,
                'template_id' => $template->id,
                'campaign_id' => $campaign->id,
                'tracked' => (bool) $campaign->track,
                'to_email' => $email,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $template->reply_to,
                'subject' => $this->renderer->renderSubject($template, $data),
                'status' => $reason === null ? Message::STATUS_PENDING : Message::STATUS_BLOCKED,
                'error' => $reason,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return Message::query()->insertOrIgnore($rows);
    }

    /**
     * Сколько писем организации ушло в очередь с начала суток (по часовому поясу окна отправки).
     * Письма рассылок считаются по моменту постановки в очередь, служебные — по созданию.
     */
    public function sentToday(Organization $organization): int
    {
        $start = $this->schedule->startOfLocalDay(now());
        $messages = fn () => Message::query()->where('organization_id', $organization->id);

        return $messages()->whereNull('campaign_id')->where('created_at', '>=', $start)->where('status', '!=', Message::STATUS_BLOCKED)->count()
            + $messages()->whereNotNull('campaign_id')->where('dispatched_at', '>=', $start)->count();
    }

    /**
     * Сколько писем организация ещё может отправить сегодня. null — без ограничения.
     */
    public function remainingToday(Organization $organization): ?int
    {
        return $organization->daily_limit ? max(0, $organization->daily_limit - $this->sentToday($organization)) : null;
    }

    public function dispatch(Message $message): void
    {
        $queue = $message->campaign_id !== null ? config('sender.queue') : config('sender.transactional_queue');

        SendMessageJob::dispatch($message->id)->onQueue($queue);
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

    /**
     * Настройки отправителя в шаблоне главнее значений из запроса: приложению достаточно передать шаблон.
     *
     * @return array{0: string, 1: string|null, 2: Domain|null, 3: SenderAddress|null}
     */
    private function sender(Organization $organization, Template $template, ?string $fromEmail, ?string $fromName): array
    {
        $sender = $template->senderAddress;
        $fromEmail = Str::lower(trim((string) ($sender?->email ?: $fromEmail)));
        $fromName = $sender?->name ?: $fromName;

        $domain = $organization->domains()
            ->where('domain', Str::after($fromEmail, '@'))
            ->first();

        return [$fromEmail, $fromName, $domain, $sender];
    }

    private function senderBlockReason(?Domain $domain, string $fromEmail, ?SenderAddress $sender): ?string
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

        return null;
    }

    private function limitReason(Organization $organization): ?string
    {
        return $this->remainingToday($organization) === 0
            ? "Дневной лимит организации исчерпан: {$organization->daily_limit} писем в сутки"
            : null;
    }

    /**
     * Отписка запрещает только рассылки: служебные письма (сброс пароля) отписавшемуся по-прежнему нужны.
     */
    private function suppressionReason(?string $reason, bool $isCampaign): ?string
    {
        if ($reason === null) {
            return null;
        }

        if ($reason === Suppression::REASON_UNSUBSCRIBE) {
            return $isCampaign ? 'Получатель отписался от рассылок' : null;
        }

        return 'Адрес получателя в списке блокировок';
    }
}
