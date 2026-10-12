<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ProfilePasswordRequest;
use App\Sender\Http\Requests\Admin\ProfileRequest;
use App\Sender\Http\Resources\UserResource;
use App\Sender\Models\McpToken;
use App\Sender\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Профиль: каждый, включая роль «просмотр», меняет только свои имя и пароль.
 */
class ProfileController extends Controller
{
    use ResolvesOrganization;

    /**
     * Данные пользователя как в /me и способы входа через Яндекс и ВКонтакте.
     */
    public function show(Request $request): UserResource
    {
        $user = $this->user($request);

        return (new UserResource($user))->additional([
            'providers' => $user->socialAccounts()->orderBy('provider')->pluck('provider')->all(),
        ]);
    }

    public function update(ProfileRequest $request): UserResource
    {
        $user = $this->user($request);
        $user->forceFill(['name' => $request->validated('name')])->save();

        return new UserResource($user);
    }

    /**
     * После смены пароля остальные входы и все ключи MCP закрываются, текущий вход остаётся.
     * Если пароль ещё не задан (вход через Яндекс или ВКонтакте), текущий не спрашивается.
     */
    public function password(ProfilePasswordRequest $request, AuthService $auth): JsonResponse
    {
        $user = $this->user($request);
        $hadPassword = $request->hasPassword();

        if ($hadPassword && ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно']);
        }

        $user->getConnection()->transaction(function () use ($user, $request, $auth): void {
            $user->forceFill(['password' => $request->string('password')->toString(), 'password_set' => true])->save();
            $auth->revokeOtherTokens($user, (string) $request->bearerToken());
            McpToken::query()->where('user_id', $user->id)->active()->update(['revoked_at' => now()]);
        });

        return response()->json(['message' => $hadPassword
            ? 'Пароль изменён. На других устройствах нужно войти заново, ключи MCP отозваны'
            : 'Пароль задан. Теперь можно входить по email и паролю']);
    }
}
