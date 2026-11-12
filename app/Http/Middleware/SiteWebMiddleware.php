<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

class SiteWebMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('deployment.service') === 'sender') {
            return $next($request);
        }

        return app(Pipeline::class)->send($request)->through([
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ])->then($next);
    }
}
