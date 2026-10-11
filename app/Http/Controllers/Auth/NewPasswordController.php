<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Страница ввода нового пароля (открывается по ссылке из письма).
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => (string) $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Сохраняет новый пароль, который человек придумал сам.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower(trim($request->email)),
                'token' => $request->token,
                'password' => $request->password,
                'password_confirmation' => $request->password_confirmation,
            ],
            function (User $user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Пароль изменён. Войдите с новым паролем.');
        }

        // Неверный токен и незнакомый email отвечают одинаково: не раскрываем, кто зарегистрирован
        throw ValidationException::withMessages([
            'email' => ['Ссылка устарела или уже использована. Запросите новую на странице «Забыли пароль».'],
        ]);
    }
}
