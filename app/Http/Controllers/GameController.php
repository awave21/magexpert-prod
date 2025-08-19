<?php

namespace App\Http\Controllers;

use App\Events\GameEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GameController extends Controller
{
    public function index()
    {
        return Inertia::render('Game');
    }

    public function testWebSocket()
    {
        try {
            $message = 'Тестовое сообщение ' . now()->format('H:i:s');
            \Log::info('Отправка сообщения через WebSocket', ['message' => $message]);
            
            event(new GameEvent($message));
            
            \Log::info('Сообщение успешно отправлено');
            return response()->json(['status' => 'success', 'message' => $message]);
        } catch (\Exception $e) {
            \Log::error('WebSocket Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
