<?php

namespace App\Sender\Services;

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Jobs\SendCampaignJob;
use App\Sender\Jobs\SendMessageJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Suppression;
use App\Sender\Support\SenderUi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Жизнь рассылки: запуск → проверка адресов → подготовка писем (pending) → пачки в очередь по расписанию → завершение.
 * Пачки ставит в очередь sender:campaigns-tick раз в минуту; пауза и отмена останавливают ещё не ушедшие письма.
 * Перед каждой пачкой письма сверяются с базой: отписавшийся после подготовки письмо не получит.
 */
class CampaignService
{
    /**
     * Сколько подписчиков готовит одна задача подготовки: потом она ставит в очередь своё продолжение.
     */
    public const PREPARE_PER_JOB = 5000;

    /**
     * Сколько писем одной рассылки может одновременно стоять в Redis: очередь пополняется по мере отправки,
     * поэтому письмо ждёт воркер минуты, а не часы, и не попадает под восстановление «потерянных» задач.
     */
    public const QUEUE_WINDOW = 1000;

    public function __construct(
        private readonly MessageService $messages,
        private readonly CampaignSchedule $schedule,
        private readonly SubscriberSources $sources,
        private readonly Warmup $warmup,
        private readonly EmailChecker $checker,
    ) {}

    /**
     * Запускает рассылку: адреса проверяются и письма готовятся фоновой задачей, а уходят сразу или по расписанию.
     */
    public function start(Campaign $campaign): Campaign
    {
        $errors = [];

        if (! $campaign->isDraft()) {
            $errors['campaign'] = 'Рассылка уже запущена';
        }

        if ($campaign->template === null) {
            $errors['template_id'] = 'Выберите контент';
        }

        if ($campaign->list === null) {
            $errors['list_id'] = 'Выберите базу подписчиков';
        }

        $recipients = $campaign->list?->contacts()->deliverable()->forCampaign($campaign)->count() ?? 0;

        if ($campaign->list !== null && $recipients === 0) {
            $errors['list_id'] = empty($campaign->segment) && $campaign->segment_id === null
                ? 'В базе нет подписчиков, которым можно отправить письмо: все отписались или адреса не прошли проверку'
                : 'Под условия отбора не подходит ни один подписчик базы';
        }

        if ($campaign->isDrip() && ($campaign->batch_size ?? 0) < 1) {
            $errors['batch_size'] = 'Укажите, сколько писем отправлять за раз';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $scheduled = $campaign->scheduled_at !== null && $campaign->scheduled_at->isFuture();

        $campaign->forceFill([
            'status' => $scheduled ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_SENDING,
            'recipients_count' => $recipients,
            'rejected_count' => 0,
            'started_at' => now(),
            'next_batch_at' => $scheduled ? $campaign->scheduled_at : now(),
        ])->save();

        SendCampaignJob::dispatch($campaign->id)->onQueue(config('sender.queue'));

        return $campaign;
    }

    /**
     * Проверяет ещё не проверенные адреса рассылки перед подготовкой писем: не больше CheckContactsJob::PER_JOB адресов
     * и CheckContactsJob::MAX_SECONDS секунд за раз. Адрес с опечаткой, одноразовый или без почтового сервера письмо не получит:
     * его письмо сразу блокируется с причиной, чтобы адрес был виден в отчёте рассылки.
     * Проход один: адрес, по которому DNS не ответил, остаётся непроверенным и письмо получает.
     *
     * @return int|null id последнего проверенного подписчика, если остались ещё; null — проверка закончена
     */
    public function checkAddresses(Campaign $campaign, int $afterContactId = 0): ?int
    {
        $campaign->loadMissing(['organization', 'template.senderAddress', 'list']);

        if ($campaign->list === null) {
            return null;
        }

        // база из приложения обновляется перед проверкой, в фоне: письмо получат те, кто согласен на рассылку сейчас
        if ($afterContactId === 0 && $campaign->list->isSynced()) {
            $this->sources->sync($campaign->list);
        }

        $started = microtime(true);
        $checked = 0;
        $rejected = [];
        $next = null;

        $contacts = $campaign->list->contacts()->deliverable()->forCampaign($campaign)
            ->whereNull('checked_at')
            ->where('id', '>', $afterContactId)
            ->lazyById(100);

        foreach ($contacts as $contact) {
            $this->checker->checkContacts([$contact]);
            $checked++;

            if (in_array($contact->check_status, EmailChecker::UNDELIVERABLE, true)) {
                $reason = EmailChecker::rejectionReason($contact->check_status, $contact->check_hint);
                $rejected[$reason][$contact->email] = array_merge($contact->data ?? [], $this->contactVariables($contact));
            }

            if ($checked >= CheckContactsJob::PER_JOB || microtime(true) - $started >= CheckContactsJob::MAX_SECONDS) {
                $next = (int) $contact->id;
                break;
            }
        }

        if ($rejected !== []) {
            if ($campaign->template !== null) {
                foreach ($rejected as $reason => $recipients) {
                    $this->messages->prepareCampaignMessages($campaign, $recipients, $reason);
                }
            }

            $campaign->increment('rejected_count', array_sum(array_map('count', $rejected)));
        }

        return $next;
    }

    /**
     * Готовит письма подписчикам с id больше $afterContactId, не больше PREPARE_PER_JOB.
     * Повторный запуск не дублирует письма.
     *
     * @return int|null id последнего подготовленного подписчика, если остались ещё; null — подготовка закончена
     */
    public function prepare(Campaign $campaign, int $afterContactId = 0): ?int
    {
        $campaign->loadMissing(['organization', 'template.senderAddress', 'list']);

        if ($campaign->template === null || $campaign->list === null) {
            $campaign->forceFill(['status' => Campaign::STATUS_SENT, 'finished_at' => now()])->save();

            return null;
        }

        $contacts = $campaign->list->contacts()->deliverable()->forCampaign($campaign)
            ->where('id', '>', $afterContactId)
            ->orderBy('id')
            ->limit(self::PREPARE_PER_JOB + 1)
            ->get();

        $more = $contacts->count() > self::PREPARE_PER_JOB;
        $contacts = $contacts->take(self::PREPARE_PER_JOB);

        foreach ($contacts->chunk(500) as $chunk) {
            $recipients = [];

            /** @var Contact $contact */
            foreach ($chunk as $contact) {
                $recipients[$contact->email] = array_merge($contact->data ?? [], $this->contactVariables($contact));
            }

            $this->messages->prepareCampaignMessages($campaign, $recipients);
        }

        if ($more) {
            return (int) $contacts->last()->id;
        }

        $campaign->forceFill([
            'prepared_at' => now(),
            'recipients_count' => $campaign->messages()->count(),
        ])->save();

        $this->tick($campaign);

        return null;
    }

    /**
     * Обрабатывает все запущенные рассылки: вызывается раз в минуту командой sender:campaigns-tick.
     */
    public function tickAll(?CarbonInterface $now = null): void
    {
        Campaign::query()
            ->whereIn('status', Campaign::ACTIVE_STATUSES)
            ->whereNotNull('prepared_at')
            ->orderBy('id')
            ->each(fn (Campaign $campaign) => $this->tick($campaign, $now));
    }

    /**
     * Ставит в очередь следующую пачку, если подошло её время, и завершает рассылку, когда все письма ушли.
     *
     * @return int сколько писем поставлено в очередь
     */
    public function tick(Campaign $campaign, ?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());

        if (! in_array($campaign->status, Campaign::ACTIVE_STATUSES, true) || $campaign->prepared_at === null) {
            return 0;
        }

        if ($campaign->status === Campaign::STATUS_SCHEDULED) {
            if ($campaign->scheduled_at !== null && $campaign->scheduled_at->greaterThan($now)) {
                return 0;
            }

            $campaign->forceFill(['status' => Campaign::STATUS_SENDING])->save();
        }

        $pending = $campaign->messages()->where('status', Message::STATUS_PENDING);

        if (! (clone $pending)->exists()) {
            $this->finishIfDone($campaign);

            return 0;
        }

        if ($campaign->next_batch_at !== null && $campaign->next_batch_at->greaterThan($now)) {
            return 0;
        }

        if ($campaign->isDrip() && ! $this->schedule->inWindow($campaign, $now)) {
            return 0;
        }

        $limit = $this->batchLimit($campaign, $now);

        if ($limit <= 0) {
            return 0;
        }

        $ids = $this->withoutDropped($campaign, (clone $pending)->orderBy('id')->limit($limit)->pluck('to_email', 'id'));

        // письмо, которое успели отменить между выборкой и отправкой, задача отправки пропустит сама
        foreach (array_chunk($ids, 1000) as $chunk) {
            Message::query()->whereIn('id', $chunk)->where('status', Message::STATUS_PENDING)
                ->update(['status' => Message::STATUS_QUEUED, 'dispatched_at' => $now]);

            foreach ($chunk as $id) {
                SendMessageJob::dispatch($id)->onQueue(config('sender.queue'));
            }
        }

        $campaign->forceFill([
            'next_batch_at' => $campaign->isDrip() ? $now->addMinutes(max(1, (int) $campaign->batch_interval)) : $now,
        ])->save();

        return count($ids);
    }

    /**
     * Пауза: новые пачки не ставятся, письма, уже стоящие в очереди, возвращаются ждать.
     */
    public function pause(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, Campaign::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages(['campaign' => 'Поставить на паузу можно только запущенную рассылку']);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_PAUSED, 'paused_at' => now()])->save();

        return $campaign;
    }

    public function resume(Campaign $campaign): Campaign
    {
        if ($campaign->status !== Campaign::STATUS_PAUSED) {
            throw ValidationException::withMessages(['campaign' => 'Рассылка не на паузе']);
        }

        $scheduled = $campaign->scheduled_at !== null && $campaign->scheduled_at->isFuture();

        $campaign->forceFill([
            'status' => $scheduled ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_SENDING,
            'paused_at' => null,
            'next_batch_at' => $scheduled ? $campaign->scheduled_at : now(),
        ])->save();

        $this->tick($campaign);

        return $campaign;
    }

    /**
     * Отмена: ни одно ещё не ушедшее письмо не отправится. Ушедшие письма и статистика остаются.
     */
    public function cancel(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [...Campaign::ACTIVE_STATUSES, Campaign::STATUS_PAUSED], true)) {
            throw ValidationException::withMessages(['campaign' => 'Отменить можно только запущенную рассылку']);
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'finished_at' => now(),
        ])->save();

        $campaign->messages()->whereIn('status', [Message::STATUS_PENDING, Message::STATUS_QUEUED])
            ->update(['status' => Message::STATUS_CANCELLED, 'error' => 'Рассылка отменена']);

        return $campaign;
    }

    /**
     * Ожидаемое время отправки последней пачки плавной рассылки.
     */
    public function estimatedFinish(Campaign $campaign, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        if (! in_array($campaign->status, [...Campaign::ACTIVE_STATUSES, Campaign::STATUS_PAUSED], true)) {
            return null;
        }

        $now ??= now();
        $remaining = $campaign->prepared_at === null
            ? $campaign->recipients_count
            : $campaign->messages()->where('status', Message::STATUS_PENDING)->count();

        return $this->schedule->estimateFinish($campaign, $remaining, $this->dispatchedToday($campaign, $now), $now);
    }

    /**
     * Тестовое письмо рассылки на свой адрес: как его увидит подписчик (его переменные, ссылка отписки внизу),
     * тема с пометкой «Тест». В статистику рассылки не попадает.
     *
     * @throws ValidationException
     */
    public function sendTest(Campaign $campaign, string $to, ?string $contactEmail = null): Message
    {
        $campaign->loadMissing(['organization', 'template.senderAddress', 'list']);

        if ($campaign->template === null) {
            throw ValidationException::withMessages(['template_id' => 'Выберите контент']);
        }

        $contacts = $campaign->list?->contacts();
        $contact = $contactEmail !== null
            ? $contacts?->where('email', Str::lower(trim($contactEmail)))->first()
            : $contacts?->deliverable()->forCampaign($campaign)->orderBy('id')->first();

        if ($contactEmail !== null && $contact === null) {
            throw ValidationException::withMessages(['contact_email' => 'Такого адреса нет в базе рассылки']);
        }

        $data = $contact !== null ? array_merge($contact->data ?? [], $this->contactVariables($contact)) : [];
        $domain = $campaign->organization->domains()->where('status', Domain::STATUS_VERIFIED)->oldest('id')->first();

        return $this->messages->send(
            $campaign->organization,
            $campaign->template,
            $to,
            [...$data, 'campaign_test' => true, 'unsubscribe_url' => SenderUi::url('unsubscribe/'.Str::uuid())],
        );
    }

    /**
     * Имя подписчика доступно в письме под теми же ключами, что и в служебных письмах сайта.
     *
     * @return array<string, string>
     */
    public function contactVariables(Contact $contact): array
    {
        $name = trim((string) $contact->name);

        return array_filter([
            'email' => $contact->email,
            'user_email' => $contact->email,
            'name' => $name,
            'user_name' => $name,
            'first_name' => $name !== '' ? explode(' ', $name)[0] : '',
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * pending — ждут своей пачки, queued — в очереди или отправляются,
     * sent — ушло с нашего сервера (в том числе доставлено), delivered — сервер получателя принял,
     * bounced — отказ сервера получателя, cancelled — не ушли из-за отмены, opened и clicked — уникальные получатели.
     *
     * @return array{pending: int, queued: int, sent: int, delivered: int, bounced: int, failed: int, blocked: int, cancelled: int, opened: int, clicked: int, complained: int, links: list<array{url: string, clicks: int}>}
     */
    public function stats(Campaign $campaign): array
    {
        $counts = $campaign->messages()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (string $status): int => (int) ($counts[$status] ?? 0);

        $links = MessageEvent::query()
            ->whereIn('message_id', $campaign->messages()->select('id'))
            ->where('type', MessageEvent::TYPE_CLICK)
            ->where('is_auto', false)
            ->selectRaw('detail as url, count(distinct message_id) as clicks')
            ->groupBy('detail')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => ['url' => (string) $row->url, 'clicks' => (int) $row->clicks])
            ->all();

        return [
            ...$this->countsByStatus($count),
            'opened' => $campaign->messages()->whereNotNull('opened_at')->count(),
            'clicked' => $campaign->messages()->whereNotNull('clicked_at')->count(),
            'complained' => MessageEvent::query()->whereIn('message_id', $campaign->messages()->select('id'))->where('type', MessageEvent::TYPE_COMPLAINT)->count(),
            'links' => $links,
        ];
    }

    /**
     * Статистика для списка рассылок: четыре запроса на все рассылки сразу, без ссылок.
     *
     * @param  iterable<Campaign>  $campaigns
     * @return array<int, array{pending: int, queued: int, sent: int, delivered: int, bounced: int, failed: int, blocked: int, cancelled: int, opened: int, clicked: int, complained: int, links: list<array{url: string, clicks: int}>}>
     */
    public function statsForMany(iterable $campaigns): array
    {
        $ids = collect($campaigns)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $byStatus = Message::query()->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, status, count(*) as total')->groupBy('campaign_id', 'status')->get()
            ->groupBy('campaign_id');

        $engagement = Message::query()->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, count(opened_at) as opened, count(clicked_at) as clicked')->groupBy('campaign_id')->get()
            ->keyBy('campaign_id');

        $complaints = MessageEvent::query()
            ->join('sender_messages', 'sender_messages.id', '=', 'sender_message_events.message_id')
            ->whereIn('sender_messages.campaign_id', $ids)
            ->where('sender_message_events.type', MessageEvent::TYPE_COMPLAINT)
            ->selectRaw('sender_messages.campaign_id, count(*) as total')->groupBy('sender_messages.campaign_id')
            ->pluck('total', 'campaign_id');

        $stats = [];

        foreach ($ids as $id) {
            $counts = ($byStatus[$id] ?? collect())->pluck('total', 'status');
            $stats[$id] = [
                ...$this->countsByStatus(fn (string $status): int => (int) ($counts[$status] ?? 0)),
                'opened' => (int) ($engagement[$id]->opened ?? 0),
                'clicked' => (int) ($engagement[$id]->clicked ?? 0),
                'complained' => (int) ($complaints[$id] ?? 0),
                'links' => [],
            ];
        }

        return $stats;
    }

    /**
     * @param  callable(string): int  $count
     * @return array{pending: int, queued: int, sent: int, delivered: int, bounced: int, failed: int, blocked: int, cancelled: int}
     */
    private function countsByStatus(callable $count): array
    {
        return [
            'pending' => $count(Message::STATUS_PENDING),
            'queued' => $count(Message::STATUS_QUEUED) + $count(Message::STATUS_SENDING),
            'sent' => $count(Message::STATUS_SENT) + $count(Message::STATUS_DELIVERED),
            'delivered' => $count(Message::STATUS_DELIVERED),
            'bounced' => $count(Message::STATUS_BOUNCED),
            'failed' => $count(Message::STATUS_FAILED),
            'blocked' => $count(Message::STATUS_BLOCKED),
            'cancelled' => $count(Message::STATUS_CANCELLED),
        ];
    }

    /**
     * Размер следующей пачки с учётом окна очереди, дневного лимита рассылки, прогрева и часового лимита сервера.
     */
    private function batchLimit(Campaign $campaign, CarbonImmutable $now): int
    {
        $limits = [self::QUEUE_WINDOW - $campaign->messages()->whereIn('status', [Message::STATUS_QUEUED, Message::STATUS_SENDING])->count()];

        if ($campaign->isDrip()) {
            $limits[] = (int) $campaign->batch_size;

            if ($campaign->daily_limit) {
                $limits[] = $campaign->daily_limit - $this->dispatchedToday($campaign, $now);
            }
        }

        $campaign->loadMissing('organization');
        $remaining = $this->messages->remainingToday($campaign->organization);
        if ($remaining !== null) {
            $limits[] = $remaining;
        }

        $warmup = $this->warmup->remainingToday($now);
        if ($warmup !== null) {
            $limits[] = $warmup;
        }

        $hourly = (int) config('sender.hourly_limit');
        if ($hourly > 0) {
            // письма подтверждения подписки идут через ту же очередь и тоже входят в часовой лимит
            $limits[] = $hourly - MessageService::bulkDispatchedSince($now->subHour());
        }

        return min($limits);
    }

    /**
     * Письма готовятся заранее, а уходят иногда через дни. Кто с тех пор отписался, пожаловался, получил отказ,
     * выбыл из базы или не прошёл проверку адреса, письмо не получит: оно блокируется с причиной.
     *
     * @param  Collection<int, string>  $batch  id письма => адрес
     * @return list<int> id писем, которые можно отправлять
     */
    private function withoutDropped(Campaign $campaign, Collection $batch): array
    {
        $campaign->loadMissing('list');

        if ($campaign->list === null || $batch->isEmpty()) {
            return $batch->keys()->all();
        }

        $emails = $batch->unique()->values()->all();
        $deliverable = $campaign->list->contacts()->deliverable()->whereIn('email', $emails)->pluck('email')->flip();
        $dropped = $batch->reject(fn (string $email): bool => isset($deliverable[$email]));

        if ($dropped->isEmpty()) {
            return $batch->keys()->all();
        }

        $suppressed = Suppression::query()->where('organization_id', $campaign->organization_id)
            ->whereIn('email', $dropped->unique()->values()->all())->pluck('reason', 'email');
        $contacts = $campaign->list->contacts()->whereIn('email', $dropped->unique()->values()->all())->get()->keyBy('email');

        $dropped
            ->groupBy(fn (string $email): string => $this->dropReason($suppressed[$email] ?? null, $contacts[$email] ?? null), true)
            ->each(fn (Collection $messages, string $reason) => Message::query()
                ->whereIn('id', $messages->keys()->all())
                ->where('status', Message::STATUS_PENDING)
                ->update(['status' => Message::STATUS_BLOCKED, 'error' => $reason]));

        return $batch->diffKeys($dropped)->keys()->all();
    }

    private function dropReason(?string $suppression, ?Contact $contact): string
    {
        return match (true) {
            $suppression === Suppression::REASON_UNSUBSCRIBE, $contact?->unsubscribed_at !== null => 'Получатель отписался от рассылок',
            $suppression !== null => 'Адрес получателя в списке блокировок',
            $contact === null => 'Подписчика больше нет в базе рассылки',
            in_array($contact->check_status, EmailChecker::UNDELIVERABLE, true) => EmailChecker::rejectionReason($contact->check_status, $contact->check_hint),
            default => 'Подписка не подтверждена',
        };
    }

    private function dispatchedToday(Campaign $campaign, CarbonInterface $now): int
    {
        return $campaign->messages()->where('dispatched_at', '>=', $this->schedule->startOfLocalDay($now))->count();
    }

    private function finishIfDone(Campaign $campaign): void
    {
        $waiting = $campaign->messages()->whereIn('status', Message::WAITING_STATUSES)->exists();

        if (! $waiting) {
            $campaign->forceFill(['status' => Campaign::STATUS_SENT, 'finished_at' => now()])->save();
        }
    }
}
