<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\LoginRequest;
use App\Sender\Http\Requests\Admin\RegisterRequest;
use App\Sender\Http\Resources\UserResource;
use App\Sender\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    use ResolvesOrganization;

    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        $result = $auth->login($request->string('email')->toString(), $request->string('password')->toString());

        if ($result === null) {
            return response()->json(['message' => 'Неверный email или пароль'], 422);
        }

        $result['user']->loadMissing('organization');

        return response()->json([
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]);
    }

    public function register(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        $result = $auth->register(
            $request->string('organization')->toString(),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        $result['user']->loadMissing('organization');

        return response()->json([
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ], 201);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->user($request));
    }

    public function logout(Request $request, AuthService $auth): JsonResponse
    {
        $auth->logout((string) $request->bearerToken());

        return response()->json(['message' => 'Вы вышли']);
    }
}
