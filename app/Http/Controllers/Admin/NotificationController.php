<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * Получение списка уведомлений
     */
    public function index()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    /**
     * Отметить уведомление как прочитанное
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('user_id', auth()->id())
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Отметить все уведомления как прочитанные
     */
    public function markAllAsRead()
    {
        Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->update([
                'read' => true,
                'read_at' => now()
            ]);

        return response()->json(['success' => true]);
    }

    /**
     * Удалить уведомление
     */
    public function destroy($id)
    {
        $notification = Notification::where('user_id', auth()->id())
            ->findOrFail($id);

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Очистить все уведомления
     */
    public function clearAll()
    {
        Notification::where('user_id', auth()->id())->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Отправка уведомления через Reverb
     */
    public static function send($userId, $notification)
    {
        // Создаем уведомление в базе данных
        $dbNotification = Notification::createNotification([
            'user_id' => $userId,
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'data' => $notification['data'] ?? null,
            'url' => $notification['url'] ?? null,
            'read' => $notification['read'] ?? false
        ]);

        // Отправляем через Reverb
        broadcast(new \App\Events\AdminNotification($userId, $dbNotification))->via('reverb');
    }

    /**
     * Тестирование отправки уведомлений
     */
    public function testNotification(Request $request)
    {
        $notification = $request->all();
        
        // Проверяем обязательные поля
        if (!isset($notification['id']) || !isset($notification['type']) || 
            !isset($notification['title']) || !isset($notification['message'])) {
            return response()->json(['error' => 'Недостаточно данных для уведомления'], 400);
        }
        
        // Добавляем timestamp, если его нет
        if (!isset($notification['timestamp'])) {
            $notification['timestamp'] = now()->toISOString();
        }
        
        // Устанавливаем read = false, если не указано
        if (!isset($notification['read'])) {
            $notification['read'] = false;
        }
        
        // Получаем всех пользователей с ролью admin
        $adminUsers = \App\Models\User::whereHas('roles', function($query) {
            $query->where('name', 'admin');
        })->get();
        
        // Отправляем уведомление всем администраторам
        foreach ($adminUsers as $admin) {
            self::send($admin->id, $notification);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Уведомление отправлено ' . $adminUsers->count() . ' администраторам'
        ]);
    }
} 