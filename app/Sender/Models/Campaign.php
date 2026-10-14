<?php

namespace App\Sender\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рассылка: контент (шаблон) по базе подписчиков.
 *
 * Отправка сразу (send_mode = now) или плавно (drip): пачками по batch_size писем раз в batch_interval минут,
 * не больше daily_limit в сутки и только в окно отправки (window_start–window_end по МСК, дни window_days).
 */
class Campaign extends SenderModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SENT = 'sent';

    /**
     * Рассылка запущена и ещё не закончилась: её обрабатывает sender:campaigns-tick.
     */
    public const ACTIVE_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_SENDING];

    public const MODE_NOW = 'now';

    public const MODE_DRIP = 'drip';

    protected $table = 'sender_campaigns';

    protected $fillable = [
        'organization_id',
        'name',
        'template_id',
        'list_id',
        'segment_id',
        'segment_name',
        'segment',
        'track',
        'send_mode',
        'batch_size',
        'batch_interval',
        'daily_limit',
        'window_start',
        'window_end',
        'window_days',
        'scheduled_at',
        'status',
        'recipients_count',
        'rejected_count',
        'started_at',
        'prepared_at',
        'next_batch_at',
        'paused_at',
        'cancelled_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
            'rejected_count' => 'integer',
            'track' => 'boolean',
            'batch_size' => 'integer',
            'batch_interval' => 'integer',
            'daily_limit' => 'integer',
            'window_days' => 'array',
            'segment' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'next_batch_at' => 'immutable_datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * В базу время пишется без пояса: «2026-11-15T07:00:00Z» из браузера приводится к поясу приложения,
     * иначе старт сдвинется на разницу поясов и рассылка уйдёт раньше.
     */
    protected function scheduledAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => blank($value)
                ? null
                : CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        );
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isDrip(): bool
    {
        return $this->send_mode === self::MODE_DRIP;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    /**
     * Сохранённый сегмент базы: рассылка уходит только тем, кто подходит под его условия.
     */
    public function savedSegment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    /**
     * Сегмент принадлежит одной базе: если базу сменили, а сегмент нет, сегмент снимается.
     * Название сегмента запоминается: после его удаления рассылка показывает, какой части базы ушла.
     */
    protected static function booted(): void
    {
        static::saving(function (Campaign $campaign): void {
            if ($campaign->segment_id !== null && $campaign->isDirty('list_id') && ! $campaign->isDirty('segment_id')) {
                $campaign->segment_id = null;
            }

            if ($campaign->isDirty('segment_id')) {
                $campaign->segment_name = $campaign->segment_id === null ? null : Segment::query()->whereKey($campaign->segment_id)->value('name');
            }
        });
    }

    /**
     * Сегмент для отчёта и редактора: действующий или удалённый (только название).
     *
     * @return array{id: int|null, name: string, deleted: bool}|null
     */
    public function segmentInfo(): ?array
    {
        if ($this->segment_id !== null && $this->savedSegment !== null) {
            return ['id' => $this->savedSegment->id, 'name' => $this->savedSegment->name, 'deleted' => false];
        }

        return $this->segment_name !== null ? ['id' => null, 'name' => $this->segment_name, 'deleted' => true] : null;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'campaign_id');
    }
}
