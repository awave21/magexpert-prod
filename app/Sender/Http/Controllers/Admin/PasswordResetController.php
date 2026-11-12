<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\ForgotPasswordRequest;
use App\Sender\Http\Requests\Admin\ResetPasswordRequest;
use App\Sender\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function request(ForgotPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->request($request->string('email')->toString());

        return response()->json(['message' => 'Если аккаунт с таким email существует и активен, мы отправим ссылку для восстановления пароля. Проверьте также папку «Спам».']);
    }

    public function reset(ResetPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        if (! $service->reset($request->string('email')->toString(), $request->string('token')->toString(), $request->string('password')->toString())) {
            throw ValidationException::withMessages(['token' => 'Ссылка недействительна или срок её действия истёк. Запросите новую.']);
        }

        return response()->json(['message' => 'Пароль изменён. Войдите с новым паролем.']);
    }
}
