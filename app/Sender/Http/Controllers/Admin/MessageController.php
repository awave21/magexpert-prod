<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Resources\MessageResource;
use App\Sender\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class MessageController extends Controller
{
    use ResolvesOrganization;

    /**
     * Группы статусов для получателей рассылки: ушло, ждёт отправки, не ушло.
     */
    private const STATUS_GROUPS = [
        'outgoing' => Message::SENT_STATUSES,
        'waiting' => Message::WAITING_STATUSES,
        'missed' => [Message::STATUS_BLOCKED, Message::STATUS_FAILED, Message::STATUS_BOUNCED, Message::STATUS_CANCELLED],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->organization($request)->messages()->with('template')->latest('id');

        if ($request->filled('campaign')) {
            $query->where('campaign_id', $request->integer('campaign'));
        }

        if (isset(self::STATUS_GROUPS[$request->input('status')])) {
            $query->whereIn('status', self::STATUS_GROUPS[$request->input('status')]);
        } elseif ($request->input('status') === 'opened') {
            $query->whereNotNull('opened_at');
        } elseif ($request->input('status') === 'clicked') {
            $query->whereNotNull('clicked_at');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('q')) {
            $query->where('to_email', 'like', '%'.$request->string('q')->lower().'%');
        }

        return MessageResource::collection($query->paginate(25)->withQueryString());
    }

    public function show(Request $request, string $uuid): MessageResource
    {
        return new MessageResource($this->organization($request)->messages()->with('template')->where('uuid', $uuid)->firstOrFail());
    }
}
