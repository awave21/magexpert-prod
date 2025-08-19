<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /**
     * Отображает главную страницу админки.
     */
    public function index(): Response
    {
        // Получаем статистику для отображения на главной странице админки
        $stats = [
            'users_count' => User::count(),
            'roles_count' => Role::count(),
            'admin_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->count(),
            'editor_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'editor');
            })->count(),
            'manager_count' => User::whereHas('roles', function ($query) {
                $query->where('name', 'manager');
            })->count(),
        ];

        return Inertia::render('Admin/Index', [
            'stats' => $stats,
            'user' => auth()->user()->load('roles'),
        ]);
    }
}
