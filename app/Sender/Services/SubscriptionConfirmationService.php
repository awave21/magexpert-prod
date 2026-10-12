<?php

namespace App\Sender\Services;

use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Message;
use App\Sender\Models\Webhook;
use App\Sender\Support\SenderUi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Подтверждение подписки для адресов, загруженных в базу без согласия.
 *
 * Каждый такой адрес получает одно письмо со ссылкой — без напоминаний и повторов: отметка confirmation_sent_at
 * ставится до отправки, и второй раз письмо этому подписчику не уходит. Письма отправляются порциями,
 * чтобы загрузка большой базы не завалила Postfix и не обошла лимиты организации и сервера.
 */
class SubscriptionConfirmationService
{
    /**
     * Сколько писем подтверждения ставится в очередь за один запуск задачи.
     */
    public const PER_RUN = 100;

    public function __construct(
        private readonly MessageService $messages,
        private readonly Warmup $warmup,
    ) {}

    public static function url(string $token): string
    {
        return SenderUi::url('confirm-subscription/'.$token);
    }

    /**
     * Подписчики базы, которым письмо подтверждения ещё не отправлялось и может уйти:
     * не отписались, адрес не забракован проверкой и не в блок-листе организации.
     *
     * @return HasMany<Contact, ContactList>
     */
    public function awaitingLetter(ContactList $list): HasMany
    {
        return $list->contacts()->pending()
            ->whereNull('confirmation_sent_at')
            ->whereNull('unsubscribed_at')
            ->where(fn (Builder $q) => $q->whereNull('check_status')->orWhereNotIn('check_status', EmailChecker::UNDELIVERABLE))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sender_suppressions')
                ->whereColumn('sender_suppressions.organization_id', 'sender_contacts.organization_id')
                ->whereColumn('sender_suppressions.email', 'sender_contacts.email'));
    }

    /**
     * Отправляет следующую порцию писем базы.
     *
     * @return array{sent: int, more: bool, limited: bool} limited — порцию урезал лимит: продолжать стоит позже
     */
    public function sendBatch(ContactList $list): array
    {
        $list->loadMissing('organization');
        $limit = $this->limit($list);

        if ($limit <= 0) {
            return ['sent' => 0, 'more' => $this->awaitingLetter($list)->exists(), 'limited' => true];
        }

        $sent = 0;

        foreach ($this->awaitingLetter($list)->orderBy('id')->limit($limit)->get() as $contact) {
            // отметка ставится до отправки и только одной задачей: письмо уходит подписчику не больше одного раза
            $claimed = Contact::query()->whereKey($contact->id)->whereNull('confirmation_sent_at')
                ->update(['confirmation_sent_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $this->messages->sendSubscriptionConfirmation($list->organization, $contact->email, self::url((string) $contact->confirmation_token), $contact->name);
            $sent++;
        }

        return ['sent' => $sent, 'more' => $this->awaitingLetter($list)->exists(), 'limited' => $limit < self::PER_RUN];
    }

    /**
     * Подтверждает подписку по ключу из ссылки. Повторное подтверждение ничего не меняет.
     *
     * @return array{0: Contact|null, 1: bool} подписчик и было ли это первое подтверждение
     */
    public function confirm(string $token): array
    {
        $contact = $this->findByToken($token);

        if ($contact === null) {
            return [null, false];
        }

        if ($contact->confirmed_at !== null || $contact->unsubscribed_at !== null) {
            return [$contact, false];
        }

        $first = Contact::query()->whereKey($contact->id)->whereNull('confirmed_at')->update(['confirmed_at' => now()]) > 0;
        $contact->refresh();

        if ($first) {
            app(WebhookService::class)->dispatch($contact->organization_id, Webhook::EVENT_CONFIRMED, [
                'email' => $contact->email,
                'list_id' => $contact->list_id,
            ]);
        }

        return [$contact, $first];
    }

    /**
     * Подписчик по ключу из ссылки. Базы из приложения подтверждения не требуют.
     */
    public function findByToken(string $token): ?Contact
    {
        $contact = Contact::query()->with('list.organization')->where('confirmation_token', $token)->first();

        return $contact?->list !== null && ! $contact->list->isSynced() ? $contact : null;
    }

    /**
     * Сколько писем можно поставить сейчас: порция, дневной лимит организации, прогрев IP
     * и часовой лимит сервера (письма рассылок и подтверждений за последний час).
     */
    private function limit(ContactList $list): int
    {
        $limits = [self::PER_RUN];

        $remaining = $this->messages->remainingToday($list->organization);
        if ($remaining !== null) {
            $limits[] = $remaining;
        }

        $warmup = $this->warmup->remainingToday();
        if ($warmup !== null) {
            $limits[] = $warmup;
        }

        $hourly = (int) config('sender.hourly_limit');
        if ($hourly > 0) {
            $since = now()->subHour();
            $limits[] = $hourly
                - Message::query()->whereNotNull('campaign_id')->where('dispatched_at', '>', $since)->count()
                - Message::query()->whereNull('campaign_id')->whereNull('template_id')->where('dispatched_at', '>', $since)->count();
        }

        return min($limits);
    }
}
