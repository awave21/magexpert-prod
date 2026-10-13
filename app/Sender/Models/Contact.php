<?php

namespace App\Sender\Models;

use App\Sender\Services\EmailChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписчик в базе. Дополнительные колонки из файла хранятся в data и доступны в письме как переменные.
 */
class Contact extends SenderModel
{
    public const TAG_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,63}$/';

    public const MAX_TAGS = 100;

    protected $table = 'sender_contacts';

    protected $fillable = ['organization_id', 'list_id', 'email', 'name', 'data', 'tags', 'unsubscribed_at', 'check_status', 'check_hint', 'checked_at', 'last_opened_at',
        'confirmation_token', 'confirmation_sent_at', 'confirmed_at'];

    protected $hidden = ['confirmation_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'tags' => 'array',
            'unsubscribed_at' => 'datetime',
            'checked_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Теги в нижнем регистре без пустых и повторов.
     *
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (is_string($tag) && ($tag = mb_strtolower(trim($tag))) !== '') {
                $clean[$tag] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    /**
     * Подписан: не отписался и не ждёт подтверждения подписки.
     */
    public function scopeSubscribed(Builder $query): void
    {
        $query->whereNull('unsubscribed_at')->confirmed();
    }

    /**
     * Загружен без согласия и ещё не подтвердил подписку по ссылке из письма.
     */
    public function isPending(): bool
    {
        return $this->confirmation_token !== null && $this->confirmed_at === null;
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNotNull('confirmation_token')->whereNull('confirmed_at');
    }

    /**
     * Подтверждение не требовалось (загружен с согласием) или уже получено.
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('confirmation_token')->orWhereNotNull('confirmed_at'));
    }

    /**
     * Часть базы: открывали письма за последние active_days дней и (или) поле field из загруженных колонок
     * равно одному из values. Пустой сегмент — вся база.
     *
     * Теги: подписчик имеет хотя бы один из перечисленных. Условия складываются.
     *
     * @param  array{active_days?: int|null, field?: string|null, values?: list<string>|null, tags?: list<string>|null}|null  $segment
     */
    public function scopeInSegment(Builder $query, ?array $segment): void
    {
        if (! empty($segment['active_days'])) {
            $query->where('last_opened_at', '>=', now()->subDays((int) $segment['active_days']));
        }

        $field = (string) ($segment['field'] ?? '');

        if (preg_match('~^[a-z0-9_]{1,64}$~', $field) && ! empty($segment['values'])) {
            $query->whereIn('data->'.$field, array_values($segment['values']));
        }

        $tags = array_values(array_filter((array) ($segment['tags'] ?? []), fn (mixed $tag): bool => is_string($tag) && $tag !== ''));

        if ($tags !== []) {
            $query->where(function (Builder $q) use ($tags): void {
                foreach ($tags as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }
    }

    /**
     * Кому уйдёт рассылка: подписан (и подтвердил подписку, если её нужно было подтверждать), адрес не забракован проверкой (непроверенные тоже получают)
     * и не в блок-листе организации (отказ, жалоба, отписка из другой базы).
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereNull('unsubscribed_at')
            ->confirmed()
            ->where(fn (Builder $q) => $q->whereNull('check_status')->orWhereNotIn('check_status', EmailChecker::UNDELIVERABLE))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sender_suppressions')
                ->whereColumn('sender_suppressions.organization_id', 'sender_contacts.organization_id')
                ->whereColumn('sender_suppressions.email', 'sender_contacts.email'));
    }
}
