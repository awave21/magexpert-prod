<?php

namespace App\Sender\Http\Middleware;

use App\Sender\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Права по роли: просмотр доступен всем; письма в «Контенте» — дизайнеру и выше; базы и рассылки — редактору;
 * домены, адреса отправителей, ключи, вебхуки, блок-лист и команда — администратору;
 * название организации и передача владения — только владельцу.
 */
class AuthorizeRole
{
    /**
     * POST, которые ничего не меняют, правка своего профиля (имя, пароль) и свои ключи для ИИ: это доступно любой роли.
     */
    private const READ_ONLY = [
        'sender.admin.logout', 'sender.admin.templates.preview', 'sender.admin.lists.count',
        'sender.admin.profile.update', 'sender.admin.profile.password',
        'sender.admin.mcp-tokens.store', 'sender.admin.mcp-tokens.destroy',
    ];

    private const OWNER_ONLY = ['organization.'];

    private const ADMIN_ONLY = ['domains.', 'sender-addresses.', 'api-keys.', 'webhooks.', 'suppressions.', 'users.'];

    /**
     * Письма, папки писем, картинки для блочного редактора и переменные (подстановки в письмах): их правит и дизайнер.
     */
    private const CONTENT = ['templates.', 'template-folders.', 'assets.', 'variables.'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->attributes->get('sender_user');
        $route = (string) $request->route()?->getName();
        $name = Str::after($route, 'sender.admin.');

        if ($request->isMethodSafe() || in_array($route, self::READ_ONLY, true)) {
            return $next($request);
        }

        if (Str::startsWith($name, self::OWNER_ONLY)) {
            return $user->isOwner()
                ? $next($request)
                : response()->json(['message' => 'Это может делать только владелец организации'], 403);
        }

        if (Str::startsWith($name, self::ADMIN_ONLY) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Это может делать только администратор организации'], 403);
        }

        if (Str::startsWith($name, self::CONTENT) && $user->canEditContent()) {
            if ($name === 'templates.test' && ! $user->canEdit()
                && Str::lower(trim((string) $request->input('to'))) !== Str::lower((string) $user->email)) {
                return response()->json(['message' => 'Дизайнер может отправить тестовое письмо только себе'], 403);
            }

            return $next($request);
        }

        if (! $user->canEdit()) {
            return response()->json(['message' => $user->role === User::ROLE_DESIGNER
                ? 'Это может делать только редактор или администратор'
                : 'У вас доступ только для просмотра'], 403);
        }

        return $next($request);
    }
}
