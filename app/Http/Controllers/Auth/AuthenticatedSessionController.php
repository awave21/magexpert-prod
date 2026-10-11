<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\SocialAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        $pending = session(SocialAuthController::PENDING);

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // человек пришёл с экрана «Почти готово»: после входа привяжем Яндекс или ВКонтакте к этому аккаунту
            'linkProvider' => is_array($pending) ? SocialAccounts::NAMES[$pending['provider']] : null,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, SocialAccounts $accounts): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $message = 'Вы успешно вошли в систему.';
        $pending = $request->session()->pull(SocialAuthController::PENDING);

        if (is_array($pending)) {
            $message = $accounts->link($request->user(), $pending)
                ? $accounts->message($pending['provider'], $accounts->applyPhone($request->user(), $pending['phone'] ?? null))
                : 'Вы вошли. Этот аккаунт '.SocialAccounts::NAMES[$pending['provider']].' уже привязан к другому профилю.';
        }

        return redirect()->intended(route('dashboard', absolute: false))->with('message', $message);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
