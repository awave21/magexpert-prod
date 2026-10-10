<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\LoginRequest;
use App\Sender\Http\Requests\Admin\OAuthRegisterRequest;
use App\Sender\Http\Requests\Admin\RegisterRequest;
use App\Sender\Http\Resources\UserResource;
use App\Sender\Services\AuthService;
use App\Sender\Services\SocialLoginService;
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

    /**
     * Обмен одноразового кода после входа через Яндекс или ВКонтакте на токен.
     */
    public function oauthExchange(Request $request, SocialLoginService $social): JsonResponse
    {
        $result = $social->exchange((string) $request->input('code'));

        if ($result === null) {
            return response()->json(['message' => 'Ссылка входа устарела. Попробуйте ещё раз'], 422);
        }

        return response()->json(['token' => $result['token'], 'user' => new UserResource($result['user'])]);
    }

    /**
     * Данные от Яндекса или ВКонтакте для формы регистрации организации.
     */
    /**
     * Провайдеры, для которых в .env есть ключи: только их кнопки показываем на входе.
     */
    public function oauthProviders(): JsonResponse
    {
        return response()->json(['data' => array_values(array_filter(
            ['yandex', 'vkid'],
            fn (string $provider): bool => (bool) config("services.{$provider}.client_id"),
        ))]);
    }

    public function oauthPending(Request $request, SocialLoginService $social): JsonResponse
    {
        $pending = $social->pending((string) $request->query('code'));

        if ($pending === null) {
            return response()->json(['message' => 'Ссылка устарела: войдите через Яндекс или ВКонтакте ещё раз'], 404);
        }

        return response()->json(['data' => [
            'provider' => $pending['provider'] === 'yandex' ? 'Яндекс' : 'ВКонтакте',
            'name' => $pending['name'],
            'email' => $pending['email'],
        ]]);
    }

    public function oauthRegister(OAuthRegisterRequest $request, SocialLoginService $social): JsonResponse
    {
        $result = $social->register(
            $request->string('code')->toString(),
            $request->string('organization')->toString(),
            $request->string('email')->toString(),
        );

        $result['user']->loadMissing('organization');

        return response()->json(['token' => $result['token'], 'user' => new UserResource($result['user'])], 201);
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
