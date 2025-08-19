<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SendsayService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
     * Обработчик запроса на восстановление пароля по email.
     * Генерирует новый пароль и отправляет его на почту, если пользователь найден.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Единое сообщение вне зависимости от наличия пользователя (не раскрываем факт существования)
        $genericStatus = 'Если такой email зарегистрирован, мы отправили новый пароль на почту.';

        // Прогрессивный кулдаун: 60 сек при первом успешном запросе, далее 2 минуты
        $emailKey = Str::lower($request->email);
        $cooldownKey = 'password_reset_cooldown:' . $emailKey;
        $stageKey = 'password_reset_stage:' . $emailKey; // 0 — не было, 1 — после первого, 2 — после второго и далее

        $stage = (int) Cache::get($stageKey, 0);
        if (Cache::has($cooldownKey)) {
            $cooldownMessage = $stage <= 1
                ? 'Повторный запрос возможен через 60 секунд.'
                : 'Повторный запрос возможен через 2 минуты.';
            return back()->with('status', $cooldownMessage);
        }

        // Ищем пользователя по email
        $user = User::where('email', strtolower($request->email))->first();

        if (!$user) {
            // Ставим кулдаун даже если пользователь не найден (согласно стадии)
            if ($stage < 1) {
                Cache::put($cooldownKey, true, now()->addSeconds(60));
                Cache::put($stageKey, 1, now()->addHours(1));
            } else {
                Cache::put($cooldownKey, true, now()->addMinutes(2));
                Cache::put($stageKey, 2, now()->addHours(1));
            }
            return back()->with('status', $genericStatus);
        }

        // Генерируем новый пароль
        $generatedPassword = method_exists(Str::class, 'password')
            ? Str::password(12)
            : Str::random(16) . 'aA1!';

        // Обновляем пароль пользователя и инвалидируем remember_token
        $user->forceFill([
            'password' => Hash::make($generatedPassword),
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));

        // Пытаемся отправить письмо через Sendsay
        try {
            /** @var SendsayService $sendsay */
            $sendsay = app(SendsayService::class);
            $name = $user->full_name ?? '';
            $sendsay->sendPasswordResetEmail($user->email, $generatedPassword, $name);
        } catch (\Throwable $e) {
            // Игнорируем ошибку отправки, пароль уже обновлён
        }

        // Устанавливаем кулдаун согласно стадии
        if ($stage < 1) {
            Cache::put($cooldownKey, true, now()->addSeconds(60));
            Cache::put($stageKey, 1, now()->addHours(1));
        } else {
            Cache::put($cooldownKey, true, now()->addMinutes(2));
            Cache::put($stageKey, 2, now()->addHours(1));
        }

        return back()->with('status', $genericStatus);
    }
}
