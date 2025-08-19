<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SendsayService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Валидируем входные данные: только токен и email
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        // Генерируем новый сильный пароль (c fallback на Str::random)
        $generatedPassword = method_exists(Str::class, 'password')
            ? Str::password(12)
            : Str::random(16) . 'aA1!';

        $resetUser = null;

        // Проводим стандартный процесс сброса через брокер, но с нашим сгенерированным паролем
        $status = Password::reset([
            'email' => $request->email,
            'token' => $request->token,
            'password' => $generatedPassword,
            'password_confirmation' => $generatedPassword,
        ], function ($user) use (&$resetUser, $generatedPassword) {
            $user->forceFill([
                'password' => Hash::make($generatedPassword),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));

            $resetUser = $user;
        });

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            // Пытаемся отправить письмо с новым паролем через Sendsay
            try {
                /** @var SendsayService $sendsay */
                $sendsay = app(SendsayService::class);
                $name = $resetUser?->full_name ?? '';
                $sendsay->sendPasswordResetEmail($request->email, $generatedPassword, $name);
            } catch (\Throwable $e) {
                // Игнорируем ошибку отправки письма, пароль уже сброшен
            }

            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
