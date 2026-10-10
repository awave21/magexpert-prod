<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCabinetMessageRequest;
use App\Models\CabinetBroadcast;
use App\Models\Event;
use App\Services\CabinetBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Сообщения в колокольчик личного кабинета: форма отправки и история.
 */
class CabinetMessageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Messages', [
            'events' => Event::query()
                ->orderByDesc('start_date')
                ->get(['id', 'title', 'start_date'])
                ->map(fn (Event $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'date' => $event->start_date ? substr((string) $event->start_date, 0, 10) : null,
                ]),
            'history' => CabinetBroadcast::query()
                ->with(['event:id,title', 'sender:id,first_name,last_name'])
                ->latest()
                ->paginate(20)
                ->through(fn (CabinetBroadcast $broadcast): array => [
                    'id' => $broadcast->id,
                    'title' => $broadcast->title,
                    'message' => $broadcast->message,
                    'url' => $broadcast->url,
                    'audience' => $broadcast->audience,
                    'event' => $broadcast->event?->title,
                    'recipients_count' => $broadcast->recipients_count,
                    'sender' => trim(($broadcast->sender?->first_name ?? '').' '.($broadcast->sender?->last_name ?? '')),
                    'created_at' => $broadcast->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Сколько человек получит сообщение — показываем до отправки.
     */
    public function count(Request $request, CabinetBroadcaster $broadcaster): JsonResponse
    {
        $audience = (string) $request->query('audience', CabinetBroadcast::AUDIENCE_ALL);

        if ($audience === CabinetBroadcast::AUDIENCE_EVENT && ! $request->filled('event_id')) {
            return response()->json(['count' => 0]);
        }
        if ($audience === CabinetBroadcast::AUDIENCE_USER && ! $request->filled('email')) {
            return response()->json(['count' => 0]);
        }

        return response()->json([
            'count' => $broadcaster->recipients($audience, $request->integer('event_id') ?: null, $request->query('email'))->count(),
        ]);
    }

    public function store(StoreCabinetMessageRequest $request, CabinetBroadcaster $broadcaster): RedirectResponse
    {
        $broadcast = $broadcaster->send($request->validated(), $request->user());

        return back()->with('message', $broadcast->recipients_count > 0
            ? "Сообщение отправлено: получателей — {$broadcast->recipients_count}"
            : 'Сообщение сохранено, но получателей не нашлось');
    }
}
