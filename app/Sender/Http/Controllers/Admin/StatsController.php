<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Resources\MessageResource;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    use ResolvesOrganization;

    /**
     * Сводка для экрана «Обзор»: итоги и разбивка по дням за последние 7 дней.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $from = Carbon::today()->subDays(6);

        // считаем в базе: за неделю писем могут быть десятки тысяч
        $rows = $organization->messages()
            ->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $count = fn (array $statuses, ?string $day = null): int => (int) $rows
            ->filter(fn ($row): bool => in_array($row->status, $statuses, true) && ($day === null || (string) $row->day === $day))
            ->sum('total');

        $days = collect(range(0, 6))->map(function (int $offset) use ($from, $count): array {
            $day = $from->copy()->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'label' => $day->locale('ru')->isoFormat('dd'),
                'sent' => $count(Message::SENT_STATUSES, $day->toDateString()),
                'failed' => $count(Message::FAILED_STATUSES, $day->toDateString()),
            ];
        });

        return response()->json([
            'totals' => [
                'sent' => $count(Message::SENT_STATUSES),
                'queued' => $organization->messages()->whereIn('status', [Message::STATUS_QUEUED, Message::STATUS_SENDING])->count(),
                'failed' => $count(Message::FAILED_STATUSES),
                'blocked' => $count([Message::STATUS_BLOCKED]),
            ],
            'days' => $days->values(),
            'unverified_domains' => $organization->domains()->where('status', '!=', Domain::STATUS_VERIFIED)->count(),
            'recent' => MessageResource::collection($organization->messages()->with('template')->latest('id')->limit(5)->get())->resolve($request),
        ]);
    }
}
