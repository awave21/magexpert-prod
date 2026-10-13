<?php

namespace App\Services;

use App\Jobs\SyncUserToSender;
use App\Models\User;
use App\Sender\Contracts\SubscriberSource;
use Illuminate\Support\Facades\DB;

/**
 * Пользователи сайта, включившие «Письма о мероприятиях»: источник базы подписчиков в Sender.
 * Специальность, город, организация и должность доступны в письме как переменные,
 * а мероприятия, к которым есть доступ, — тегами event-<id> для отбора в рассылке.
 */
class NewsletterSubscribers implements SubscriberSource
{
    public function key(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return 'Пользователи сайта с согласием на рассылку';
    }

    public function organization(): string
    {
        return (string) config('sender.client.organization');
    }

    public function subscribers(): iterable
    {
        $lastId = 0;

        do {
            $users = User::query()
                ->where('newsletter_consent', true)
                ->whereNotNull('email')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(1000)
                ->select(['id', 'email', 'first_name', 'last_name', 'specialization', 'city', 'company', 'position'])
                ->get();

            $eventTags = $this->eventTags($users->pluck('id')->all());

            foreach ($users as $user) {
                yield [
                    'email' => (string) $user->email,
                    'name' => trim($user->first_name.' '.$user->last_name) ?: null,
                    'data' => [
                        'specialization' => (string) $user->specialization,
                        'city' => (string) $user->city,
                        'company' => (string) $user->company,
                        'position' => (string) $user->position,
                    ],
                    'tags' => $eventTags[$user->id] ?? [],
                ];
            }

            $lastId = (int) ($users->last()?->id ?? 0);
        } while ($users->count() === 1000);
    }

    /**
     * Теги мероприятий (event-<id>), к которым у пользователей есть действующий доступ: регистрация, оплата, выдача в админке.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function eventTags(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $tags = [];

        DB::table('event_user')
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->whereIn('payment_status', ['completed', 'free', 'unpaid'])
            ->where(fn ($query) => $query->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
            ->orderBy('event_id')
            ->get(['user_id', 'event_id'])
            ->each(function (object $row) use (&$tags): void {
                $tags[(int) $row->user_id][] = SyncUserToSender::eventTag((int) $row->event_id);
            });

        return $tags;
    }
}
