<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\GameEvent;

class WebSocketController extends Controller
{
    public function test()
    {
        // Отправляем тестовое событие
        broadcast(new GameEvent('Тестовое сообщение ' . now()))->toOthers();
        
        // Добавим логирование
        \Log::info('Событие отправлено');
        
        return response()->json(['status' => 'success']);
    }
} 