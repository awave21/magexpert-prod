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

        $messages = $organization->messages()
            ->where('created_at', '>=', $from)
            ->get(['status', 'created_at']);

        $days = collect(range(0, 6))->map(function (int $offset) use ($from, $messages): array {
            $day = $from->copy()->addDays($offset);
            $ofDay = $messages->filter(fn (Message $message): bool => $message->created_at->isSameDay($day));

            return [
                'date' => $day->toDateString(),
                'label' => $day->locale('ru')->isoFormat('dd'),
                'sent' => $ofDay->where('status', Message::STATUS_SENT)->count(),
                'failed' => $ofDay->where('status', Message::STATUS_FAILED)->count(),
            ];
        });

        return response()->json([
            'totals' => [
                'sent' => $messages->where('status', Message::STATUS_SENT)->count(),
                'queued' => $organization->messages()->whereIn('status', [Message::STATUS_QUEUED, Message::STATUS_SENDING])->count(),
                'failed' => $messages->where('status', Message::STATUS_FAILED)->count(),
                'blocked' => $messages->where('status', Message::STATUS_BLOCKED)->count(),
            ],
            'days' => $days->values(),
            'unverified_domains' => $organization->domains()->where('status', '!=', Domain::STATUS_VERIFIED)->count(),
            'recent' => MessageResource::collection($organization->messages()->with('template')->latest('id')->limit(5)->get())->resolve($request),
        ]);
    }
}
