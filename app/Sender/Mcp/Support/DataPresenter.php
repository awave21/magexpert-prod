<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Services\CampaignService;
use App\Sender\Services\ContactUpsertService;
use App\Sender\Support\SenderUi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Как базы, подписчики и рассылки выглядят для ассистента.
 */
class DataPresenter
{
    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * Базы с количеством подписчиков: всего, подписаны, ждут подтверждения, получат рассылку.
     *
     * @param  HasMany<ContactList, *>|Builder<ContactList>  $query
     * @return Builder<ContactList>
     */
    public function listsWithCounts(HasMany|Builder $query): Builder
    {
        return ($query instanceof HasMany ? $query->getQuery() : $query)->withCount([
            'contacts',
            'contacts as subscribed_count' => fn (Builder $q) => $q->subscribed(),
            'contacts as pending_count' => fn (Builder $q) => $q->whereNull('unsubscribed_at')->pending(),
            'contacts as deliverable_count' => fn (Builder $q) => $q->deliverable(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(ContactList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            // базу из источника (например, пользователи сайта) Sender обновляет сам: менять подписчиков в ней нельзя
            'synced' => $list->isSynced(),
            'require_confirmation' => (bool) $list->require_confirmation,
            'contacts_count' => (int) ($list->contacts_count ?? 0),
            'subscribed_count' => (int) ($list->subscribed_count ?? 0),
            'pending_count' => (int) ($list->pending_count ?? 0),
            'deliverable_count' => (int) ($list->deliverable_count ?? 0),
            'updated_at' => $list->updated_at?->toIso8601String(),
            'url' => SenderUi::url('subscribers/'.$list->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contact(Contact $contact): array
    {
        return [
            'email' => $contact->email,
            'name' => $contact->name,
            'data' => $contact->data ?: (object) [],
            'tags' => $contact->tags ?? [],
            'status' => ContactUpsertService::status($contact),
            'check_status' => $contact->check_status,
            'last_opened_at' => $contact->last_opened_at?->toIso8601String(),
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stats  посчитанная заранее статистика (список рассылок)
     * @return array<string, mixed>
     */
    public function campaign(Campaign $campaign, bool $detailed = false, ?array $stats = null): array
    {
        $stats ??= $campaign->isDraft() ? null : $this->campaigns->stats($campaign);
        $drip = $campaign->isDrip();

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'template' => $campaign->template ? ['id' => $campaign->template->id, 'name' => $campaign->template->name] : null,
            'list' => $campaign->list ? ['id' => $campaign->list->id, 'name' => $campaign->list->name] : null,
            'segment' => $campaign->segment ?: null,
            'track' => (bool) ($campaign->track ?? true),
            'send_mode' => $campaign->send_mode ?? Campaign::MODE_NOW,
            'drip' => $drip ? [
                'batch_size' => $campaign->batch_size,
                'batch_interval' => $campaign->batch_interval,
                'daily_limit' => $campaign->daily_limit,
                'window_start' => $campaign->window_start,
                'window_end' => $campaign->window_end,
                'window_days' => $campaign->window_days,
            ] : null,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'recipients_count' => $campaign->recipients_count,
            'rejected_count' => (int) $campaign->rejected_count,
            // сколько подписчиков получит черновик, если запустить его сейчас
            'deliverable_count' => $detailed && $campaign->isDraft()
                ? ($campaign->list?->contacts()->deliverable()->forCampaign($campaign)->count() ?? 0)
                : null,
            'estimated_finish_at' => $detailed ? $this->campaigns->estimatedFinish($campaign)?->toIso8601String() : null,
            'stats' => $stats,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'url' => SenderUi::url('campaigns/'.$campaign->id),
        ];
    }
}
