<?php

namespace App\Sender\Services;

use App\Sender\Jobs\SendCampaignJob;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\Message;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * Запускает рассылку: письма ставятся в очередь фоновой задачей, по одному на каждого подписчика базы.
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

        $recipients = $campaign->list?->contacts()->deliverable()->count() ?? 0;

        if ($campaign->list !== null && $recipients === 0) {
            $errors['list_id'] = 'В базе нет подписчиков, которым можно отправить письмо: все отписались или адреса не прошли проверку';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_SENDING,
            'recipients_count' => $recipients,
            'started_at' => now(),
        ])->save();

        SendCampaignJob::dispatch($campaign->id)->onQueue(config('sender.queue'));

        return $campaign;
    }

    /**
     * Ставит письма в очередь. Повторный запуск задачи не дублирует письма: уже поставленные адреса пропускаются.
     */
    public function dispatchMessages(Campaign $campaign): void
    {
        $campaign->loadMissing(['organization', 'template.senderAddress', 'list']);

        if ($campaign->template === null || $campaign->list === null) {
            $campaign->forceFill(['status' => Campaign::STATUS_SENT, 'finished_at' => now()])->save();

            return;
        }

        $campaign->list->contacts()->deliverable()->chunkById(500, function ($contacts) use ($campaign): void {
            $already = Message::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('to_email', $contacts->pluck('email'))
                ->pluck('to_email')
                ->flip();

            /** @var Contact $contact */
            foreach ($contacts as $contact) {
                if ($already->has($contact->email)) {
                    continue;
                }

                $this->messages->send(
                    $campaign->organization,
                    $campaign->template,
                    $contact->email,
                    data: array_merge($contact->data ?? [], $this->contactVariables($contact)),
                    campaign: $campaign,
                );
            }
        });

        $campaign->forceFill(['status' => Campaign::STATUS_SENT, 'finished_at' => now()])->save();
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
     * @return array{queued: int, sent: int, failed: int, blocked: int}
     */
    public function stats(Campaign $campaign): array
    {
        $counts = $campaign->messages()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'queued' => (int) ($counts[Message::STATUS_QUEUED] ?? 0) + (int) ($counts[Message::STATUS_SENDING] ?? 0),
            'sent' => (int) ($counts[Message::STATUS_SENT] ?? 0),
            'failed' => (int) ($counts[Message::STATUS_FAILED] ?? 0),
            'blocked' => (int) ($counts[Message::STATUS_BLOCKED] ?? 0),
        ];
    }
}
