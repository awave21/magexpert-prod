<?php

namespace App\Sender\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Внешние системы не всегда присылают Accept: application/json. Без него Laravel отвечает на ошибку проверки
 * перенаправлением, а на 404 — страницей; с этим middleware ошибки всегда приходят в JSON.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
