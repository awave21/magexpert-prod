<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Мероприятия пользователя для личного кабинета: карточки с доступом, эфиром и записью.
 */
class CabinetEvents
{
    public const TYPES = [
        'webinar' => 'Вебинар',
        'conference' => 'Конференция',
        'course' => 'Курс',
        'workshop' => 'Мастер-класс',
        'seminar' => 'Семинар',
        'other' => 'Мероприятие',
    ];

    public const FORMATS = [
        'online' => 'Онлайн',
        'offline' => 'Офлайн',
        'hybrid' => 'Гибрид',
    ];

    /**
     * Все активные мероприятия, к которым у пользователя есть запись (оплаченные, бесплатные и ожидающие оплаты).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(User $user): Collection
    {
        $liveIds = $user->liveEvents->pluck('id')->all();

        return $user->accessibleEvents()
            ->where('events.is_active', true)
            ->get()
            ->map(fn (Event $event): array => $this->card($event, $liveIds))
            ->values();
    }

    /**
     * @param  array<int, int>  $liveIds
     * @return array<string, mixed>
     */
    public function card(Event $event, array $liveIds = []): array
    {
        $status = $event->pivot?->payment_status;
        $startDate = $event->start_date ? substr((string) $event->start_date, 0, 10) : null;
        $past = $event->is_archived || ($startDate !== null && $startDate < now()->toDateString());

        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'image' => $event->image,
            'start_date' => $startDate,
            'start_time' => $event->start_time ? substr((string) $event->start_time, 0, 5) : null,
            'type' => self::TYPES[$event->event_type] ?? null,
            'format' => self::FORMATS[$event->format] ?? null,
            'location' => $event->location,
            'is_live' => in_array($event->id, $liveIds, true),
            'is_past' => $past,
            'is_on_demand' => (bool) $event->is_on_demand,
            'has_recording' => $event->hasKinescopeRecord(),
            'access' => match ($status) {
                'completed' => 'paid',
                'free' => 'free',
                'unpaid' => 'request',
                default => 'pending',
            },
            'price' => $event->isPaid() ? $event->getFormattedPrice() : null,
        ];
    }
}
