<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailConfirmation;
use App\Services\SenderMailService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Подтверждение email по ссылке из письма «Добро пожаловать» и повторная отправка ссылки из кабинета.
 */
class EmailConfirmController extends Controller
{
    public function confirm(Request $request, int $id, string $hash, EmailConfirmation $confirmation): RedirectResponse
    {
        $target = $request->user() ? route('dashboard') : route('login');
        $user = User::query()->find($id);

        if ($user === null || ! $request->hasValidSignature() || ! $confirmation->matches($user, $hash)) {
            return redirect($target)->with('error', 'Ссылка подтверждения устарела или неверна. Отправьте новую из личного кабинета.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect($target)->with('message', $request->user() ? 'Email подтверждён. Спасибо!' : 'Email подтверждён. Войдите в аккаунт.');
    }

    public function resend(Request $request, SenderMailService $mail): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return back()->with('message', 'Email уже подтверждён.');
        }

        return $mail->sendEmailConfirmation($user)
            ? back()->with('message', "Отправили ссылку на {$user->email}. Проверьте почту, в том числе папку «Спам».")
            : back()->with('error', 'Не удалось отправить письмо. Попробуйте позже.');
    }
}
