<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Разделы личного кабинета открываются после подтверждения email и заполнения города и специализации.
 * Сотрудников (роли админки) это не касается. Пока чего-то не хватает, человека возвращают на сводку кабинета:
 * там уже подсвечено, что заполнить.
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasAnyRole(['admin', 'editor', 'manager'])) {
            return $next($request);
        }

        $missing = $user->missingProfileRequirements();

        if ($missing === []) {
            return $next($request);
        }

        $message = 'Чтобы открыть этот раздел, '.implode(', ', $missing).'.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('dashboard')->with('message', $message);
    }
}
