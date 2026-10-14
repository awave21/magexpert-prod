<?php

namespace App\Sender\Services;

use App\Sender\Jobs\SendMessageJob;
use App\Sender\Mail\SubscriptionConfirmationLetter;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Models\SenderAddress;
use App\Sender\Models\Suppression;
use App\Sender\Models\Template;
use App\Sender\Support\SenderUi;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        array $data = [],
        ?Campaign $campaign = null,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): Message {
        $to = Str::lower(trim($to));
        $uuid = (string) Str::uuid();
        $data = array_merge($this->defaults($organization), $data);

        // у каждого письма есть ссылка отписки: её можно вставить в текст как {{ unsubscribe_url }},
        // а если её в письме нет, она добавляется внизу автоматически (SendMessageJob)
        $data['unsubscribe_url'] = SenderUi::url('unsubscribe/'.$uuid);

        [$fromEmail, $fromName, $domain, $sender] = $this->sender($organization, $template);

        $message = $organization->messages()->make([
            'uuid' => $uuid,
            'idempotency_key' => $idempotencyKey,
            'request_id' => $requestId,
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
     * Письмо с просьбой подтвердить подписку. Текст собирается в коде (SubscriptionConfirmationLetter),
     * письмо попадает в журнал и уходит через очередь рассылок, без учёта открытий и переходов.
     * Отправитель — подтверждённый адрес организации на подтверждённом домене, иначе noreply@ её домена.
     * Нет подтверждённого домена — письмо блокируется. Адрес в блок-листе (в том числе отписавшийся) письмо не получит.
     */
    public function sendSubscriptionConfirmation(Organization $organization, Contact $contact): Message
    {
        $to = Str::lower(trim($contact->email));
        [$fromEmail, $fromName, $domain] = $this->organizationSender($organization);
        // ссылка с ключом подтверждения в журнал не попадает: она собирается при отправке по contact_id
        $data = [
            'system' => SubscriptionConfirmationLetter::KIND,
            'contact_id' => $contact->id,
            'organization_name' => $organization->name,
            'name' => (string) $contact->name,
        ];

        $message = $organization->messages()->make([
            'uuid' => (string) Str::uuid(),
            'domain_id' => $domain?->id,
            'template_id' => null,
            'campaign_id' => null,
            'tracked' => false,
            'to_email' => $to,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => SubscriptionConfirmationLetter::subject($organization->name),
            'status' => Message::STATUS_QUEUED,
            'data' => $data,
        ]);

        if ($fromEmail === '') {
            $message->fill(['status' => Message::STATUS_BLOCKED, 'error' => 'Нет подтверждённого домена: письмо с подтверждением подписки отправить не с чего'])->save();

            return $message;
        }

        $suppressed = Suppression::query()->where('organization_id', $organization->id)->where('email', $to)->value('reason');

        if ($suppressed !== null) {
            $message->fill(['status' => Message::STATUS_BLOCKED, 'error' => $this->suppressionReason($suppressed, true)])->save();

            return $message;
        }

        $message->dispatched_at = now();
        $message->save();
        $this->dispatch($message);

        return $message;
    }

    /**
     * С какого адреса организация отправляет письма от своего имени, когда шаблона нет.
     *
     * @return array{0: string, 1: string|null, 2: Domain|null}
     */
    private function organizationSender(Organization $organization): array
    {
        $verified = $organization->domains()->where('status', Domain::STATUS_VERIFIED)->orderBy('id')->get();

        if ($verified->isNotEmpty()) {
            $address = $organization->senderAddresses()->whereNotNull('confirmed_at')
                ->whereIn('domain_id', $verified->pluck('id'))->orderBy('id')->first();

            if ($address !== null) {
                return [Str::lower($address->email), $address->name ?: $organization->name, $verified->firstWhere('id', $address->domain_id)];
            }

            return ['noreply@'.$verified->first()->domain, $organization->name, $verified->first()];
        }

        return ['', null, null];
    }

    /**
     * Готовит письма рассылки пачкой, не отправляя их: статус pending, в очередь их ставит sender:campaigns-tick.
     * Проверки отправителя делаются один раз, блок-лист — одним запросом на пачку.
     * Адреса, которым письмо этой рассылки уже подготовлено, пропускаются (уникальный индекс).
     *
     * @param  array<string, array<string, mixed>>  $recipients  email => переменные подписчика
     * @param  string|null  $blockReason  письма сразу блокируются с этой причиной: адрес не прошёл проверку
     * @return int сколько писем добавлено
     */
    public function prepareCampaignMessages(Campaign $campaign, array $recipients, ?string $blockReason = null): int
    {
        if ($recipients === []) {
            return 0;
        }

        $organization = $campaign->organization;
        $template = $campaign->template;
        $defaults = $this->defaults($organization);

        [$fromEmail, $fromName, $domain, $sender] = $this->sender($organization, $template);
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
            $reason = $blockReason ?? $senderReason ?? $this->suppressionReason($suppressed[$email] ?? null, true);

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

    /**
     * Письма, зависшие в статусе «отправляется» дольше 15 минут: воркер упал посреди отправки.
     * Если Postfix успел принять письмо (в журнале есть его Message-ID), оно отправлено. Если нет — ставится заново.
     * Без журнала Postfix узнать нельзя, и письмо помечается ошибкой, чтобы не отправить его дважды.
     *
     * Письма «в очереди» дольше 15 минут, вероятно, потеряли задачу (Redis перезапустился без сохранения):
     * задача ставится заново. Дубля не будет: письмо забирает только одна задача.
     *
     * @return array{sent: int, requeued: int, failed: int, redispatched: int}
     */
    public function recoverStuck(): array
    {
        $result = ['sent' => 0, 'requeued' => 0, 'failed' => 0, 'redispatched' => 0];

        // updated_at обновляется при каждой постановке, поэтому длинная очередь рассылки не размножается чаще раза в 15 минут
        Message::query()->where('status', Message::STATUS_QUEUED)->where('updated_at', '<', now()->subMinutes(15))
            ->chunkById(500, function ($messages) use (&$result): void {
                foreach ($messages as $message) {
                    $message->touch();
                    $this->dispatch($message);
                    $result['redispatched']++;
                }
            });
        $logReadable = is_readable((string) config('sender.mail_log'));

        Message::query()->where('status', Message::STATUS_SENDING)->where('updated_at', '<', now()->subMinutes(15))
            ->chunkById(500, function ($messages) use (&$result, $logReadable): void {
                foreach ($messages as $message) {
                    if ($message->smtp_queue_id !== null) {
                        $message->forceFill(['status' => Message::STATUS_SENT, 'sent_at' => $message->updated_at, 'error' => null])->save();
                        $result['sent']++;
                    } elseif ($logReadable) {
                        $message->forceFill(['status' => Message::STATUS_QUEUED, 'error' => 'Отправка прервалась, письмо поставлено в очередь заново'])->save();
                        $this->dispatch($message);
                        $result['requeued']++;
                    } else {
                        $message->forceFill([
                            'status' => Message::STATUS_FAILED,
                            'error' => "Отправка прервалась, а журнал Postfix недоступен: неизвестно, ушло ли письмо. Найдите в /var/log/mail.log Message-ID {$message->uuid}",
                        ])->save();
                        $result['failed']++;
                    }
                }
            });

        return $result;
    }

    /**
     * Письма, которые идут через очередь рассылок и входят в прогрев IP и часовой лимит сервера:
     * письма рассылок и письма подтверждения подписки (у них нет шаблона, а в данных метка system).
     *
     * @return Builder<Message>
     */
    public static function bulkMessages(): Builder
    {
        return Message::query()->where(fn (Builder $q) => $q->whereNotNull('campaign_id')
            ->orWhere(fn (Builder $q) => $q->whereNull('template_id')->where('data->system', SubscriptionConfirmationLetter::KIND)));
    }

    /**
     * Сколько писем рассылок и подтверждения подписки всех организаций ушло в очередь после $since.
     */
    public static function bulkDispatchedSince(CarbonInterface $since): int
    {
        return self::bulkMessages()->where('dispatched_at', '>', $since)->count();
    }

    public function dispatch(Message $message): void
    {
        // письма подтверждения подписки уходят тысячами после загрузки базы: им место в очереди рассылок
        $bulk = $message->campaign_id !== null || ($message->data['system'] ?? null) === SubscriptionConfirmationLetter::KIND;
        $queue = $bulk ? config('sender.queue') : config('sender.transactional_queue');

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
     * Отправитель письма определяется только шаблоном: приложению достаточно передать шаблон.
     * Нет адреса в шаблоне, значит письмо блокируется (senderBlockReason), запасного адреса нет.
     *
     * @return array{0: string, 1: string|null, 2: Domain|null, 3: SenderAddress|null}
     */
    private function sender(Organization $organization, Template $template): array
    {
        $sender = $template->senderAddress;
        $fromEmail = Str::lower(trim((string) $sender?->email));
        $fromName = $sender?->name;

        $domain = $organization->domains()
            ->where('domain', Str::after($fromEmail, '@'))
            ->first();

        return [$fromEmail, $fromName, $domain, $sender];
    }

    private function senderBlockReason(?Domain $domain, string $fromEmail, ?SenderAddress $sender): ?string
    {
        if ($fromEmail === '') {
            return 'У шаблона не задан адрес отправителя: укажите его в настройках шаблона';
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
