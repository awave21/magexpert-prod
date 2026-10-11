<?php

namespace App\Sender\Http\Middleware;

use App\Sender\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateUser
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $user = $plain ? $this->auth->authenticate($plain) : null;

        if ($user === null) {
            return response()->json(['message' => 'Требуется вход'], 401);
        }

        $request->attributes->set('sender_user', $user);
        $request->attributes->set('sender_organization', $user->organization);

        return $next($request);
    }
}
