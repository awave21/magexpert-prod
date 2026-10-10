<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Колокольчик в личном кабинете: сообщения, которые команда отправила пользователю.
 */
class UserNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()
            ->latest()
            ->limit(20)
            ->get(['id', 'title', 'message', 'url', 'read', 'created_at']);

        return response()->json([
            'data' => $items,
            'unread' => $user->notifications()->where('read', false)->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->where('read', false)->update(['read' => true, 'read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
