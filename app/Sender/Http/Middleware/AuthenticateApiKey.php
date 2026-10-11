<?php

namespace App\Sender\Http\Middleware;

use App\Sender\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $key = $plain ? $this->keys->authenticate($plain) : null;

        if ($key === null) {
            return response()->json(['message' => 'Неверный или отозванный API-ключ'], 401);
        }

        $request->attributes->set('sender_organization', $key->organization);

        return $next($request);
    }
}
