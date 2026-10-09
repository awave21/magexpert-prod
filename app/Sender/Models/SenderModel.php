<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Базовая модель Sender: все таблицы модуля живут в отдельной БД.
 */
abstract class SenderModel extends Model
{
    public function getConnectionName(): string
    {
        return config('sender.connection', 'sender');
    }
}
