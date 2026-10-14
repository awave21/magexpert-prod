<?php

namespace App\Sender\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Единый формат ошибок публичного API (RFC 9457, application/problem+json).
 * Прежние ключи message и errors остаются в теле, поэтому клиенты, которые их читают, не ломаются.
 * Ресурсы с кодом ошибки (например, заблокированное письмо) не трогаются: у них есть свой id.
 */
class ApiProblemResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || array_key_exists('id', $data) || array_key_exists('status', $data)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $detail = (string) ($data['message'] ?? Response::$statusTexts[$status] ?? 'Error');

        $response->setData([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            ...$data,
            'message' => $detail,
        ]);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
