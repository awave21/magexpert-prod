<?php

namespace App\Sender\Http\Middleware;

use App\Sender\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Права по роли: просмотр доступен всем, изменения — редактору, а домены, адреса отправителей, ключи,
 * вебхуки, блок-лист и команда — только администратору организации.
 */
class AuthorizeRole
{
    /**
     * POST, которые ничего не меняют.
     */
    private const READ_ONLY = ['sender.admin.logout', 'sender.admin.templates.preview', 'sender.admin.lists.count'];

    private const ADMIN_ONLY = ['domains.', 'sender-addresses.', 'api-keys.', 'webhooks.', 'suppressions.', 'users.'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->attributes->get('sender_user');
        $route = (string) $request->route()?->getName();

        if ($request->isMethodSafe() || in_array($route, self::READ_ONLY, true)) {
            return $next($request);
        }

        if (Str::startsWith(Str::after($route, 'sender.admin.'), self::ADMIN_ONLY) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Это может делать только администратор организации'], 403);
        }

        if (! $user->canEdit()) {
            return response()->json(['message' => 'У вас доступ только для просмотра'], 403);
        }

        return $next($request);
    }
}
