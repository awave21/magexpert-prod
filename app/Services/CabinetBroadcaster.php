<?php

namespace App\Services;

use App\Models\CabinetBroadcast;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Рассылка сообщений команды в колокольчик кабинета. Сайт сам ничего не отправляет — только по кнопке в админке.
 */
class CabinetBroadcaster
{
    /**
     * Кому уйдёт сообщение. Удалённые (обезличенные) аккаунты не получают ничего.
     *
     * @return Builder<User>
     */
    public function recipients(string $audience, ?int $eventId = null, ?string $email = null): Builder
    {
        $query = User::query()->where('email', 'not like', 'deleted-%@mag-expert.invalid');

        return match ($audience) {
            CabinetBroadcast::AUDIENCE_EVENT => $query->whereHas('events', fn (Builder $events) => $events
                ->where('events.id', $eventId)
                ->where('event_user.is_active', true)),
            CabinetBroadcast::AUDIENCE_USER => $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))]),
            default => $query,
        };
    }

    /**
     * @param  array{title: string, message: string, url?: ?string, audience: string, event_id?: ?int, email?: ?string}  $data
     */
    public function send(array $data, User $sender): CabinetBroadcast
    {
        return DB::transaction(function () use ($data, $sender): CabinetBroadcast {
            $broadcast = CabinetBroadcast::create([
                'title' => $data['title'],
                'message' => $data['message'],
                'url' => $data['url'] ?? null,
                'audience' => $data['audience'],
                'event_id' => $data['audience'] === CabinetBroadcast::AUDIENCE_EVENT ? $data['event_id'] : null,
                'sent_by' => $sender->id,
            ]);

            $count = 0;
            $now = now();
            $payload = json_encode(['broadcast_id' => $broadcast->id]);

            $this->recipients($data['audience'], $data['event_id'] ?? null, $data['email'] ?? null)
                ->select('users.id')
                ->chunkById(1000, function ($users) use (&$count, $broadcast, $now, $payload): void {
                    Notification::query()->insert($users->map(fn (User $user): array => [
                        'user_id' => $user->id,
                        'type' => 'cabinet',
                        'title' => $broadcast->title,
                        'message' => $broadcast->message,
                        'url' => $broadcast->url,
                        'data' => $payload,
                        'read' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                    $count += $users->count();
                }, 'users.id', 'id');

            $broadcast->update(['recipients_count' => $count]);

            return $broadcast;
        });
    }
}
