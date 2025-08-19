<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class UserRoleController extends Controller
{
    /**
     * Отображает форму для управления ролями пользователя.
     */
    public function edit(User $user): Response
    {
        if (Gate::denies('manage-roles')) {
            abort(403, 'У вас нет прав для управления ролями пользователей');
        }
        
        return Inertia::render('Users/Roles', [
            'user' => $user->load('roles'),
            'roles' => Role::all(),
        ]);
    }

    /**
     * Обновляет роли пользователя.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        // Изменяем проверку прав с Gate на прямую проверку роли
        if (!auth()->user()->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'У вас нет прав для управления ролями пользователей');
        }
        
        try {
            // Отладочная информация
            info('Запрос на обновление ролей: ' . json_encode($request->all()));
            info('Пользователь: ' . auth()->user()->name . ', роли: ' . implode(', ', auth()->user()->roles->pluck('name')->toArray()));
            
            $validated = $request->validate([
                'roles' => ['required', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
            ]);

            // Отладочная информация
            info('Валидированные роли: ' . json_encode($validated['roles']));
            
            // Получаем ID ролей по их именам
            $roleIds = Role::whereIn('name', $validated['roles'])->pluck('id');
            
            // Отладочная информация
            info('ID ролей для синхронизации: ' . json_encode($roleIds->toArray()));
            
            // Синхронизируем роли пользователя
            $user->roles()->sync($roleIds);
            
            info('Роли пользователя успешно обновлены.');
            
            return redirect()->back()
                ->with('success', 'Роли пользователя успешно обновлены.');
        } catch (\Exception $e) {
            info('Ошибка при обновлении ролей: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Ошибка при обновлении ролей: ' . $e->getMessage());
        }
    }

    /**
     * Добавляет роль пользователю.
     */
    public function addRole(Request $request, User $user): RedirectResponse
    {
        if (Gate::denies('manage-roles')) {
            abort(403, 'У вас нет прав для управления ролями пользователей');
        }
        
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user->roles()->syncWithoutDetaching([$validated['role_id']]);

        return back()->with('success', 'Роль успешно добавлена пользователю.');
    }

    /**
     * Удаляет роль у пользователя.
     */
    public function removeRole(Request $request, User $user): RedirectResponse
    {
        if (Gate::denies('manage-roles')) {
            abort(403, 'У вас нет прав для управления ролями пользователей');
        }
        
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user->roles()->detach($validated['role_id']);

        return back()->with('success', 'Роль успешно удалена у пользователя.');
    }
}
