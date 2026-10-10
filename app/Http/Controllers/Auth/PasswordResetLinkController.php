<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Отправляет на почту ссылку для смены пароля. Сам пароль не меняется, пока человек не перейдёт по ссылке.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Повторную ссылку тому же человеку брокер не отправит раньше чем через минуту
        Password::sendResetLink(['email' => mb_strtolower(trim($request->email))]);

        // Ответ одинаковый, есть такой email или нет: не раскрываем, кто зарегистрирован
        return back()->with('status', 'Если такой email зарегистрирован, мы отправили на него ссылку для смены пароля. Она действует 60 минут.');
    }
}
