<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Авторизация для канала уведомлений админа
Broadcast::channel('admin.notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
