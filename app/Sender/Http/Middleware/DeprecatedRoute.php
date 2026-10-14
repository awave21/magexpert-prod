<?php

namespace App\Sender\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Помечает устаревший метод API: Deprecation: true и Link на документацию, где указана замена.
 * Метод продолжает работать; клиенту стоит перейти на каноничный адрес.
 */
class DeprecatedRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '</api/sender/v1/docs>; rel="deprecation"; type="text/html"');

        return $response;
    }
}
