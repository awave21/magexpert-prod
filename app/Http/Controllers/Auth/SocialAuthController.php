<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCompleteRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SenderMailService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Вход и регистрация через Яндекс ID и VK ID.
 *
 * Известный аккаунт входит сразу. Если email от провайдера уже есть на сайте, вход привязывается к этому аккаунту.
 * Новый человек попадает на экран согласий (и email, если провайдер его не отдал), после чего создаётся аккаунт.
 */
class SocialAuthController extends Controller
{
    private const NAMES = ['yandex' => 'Яндекс', 'vkid' => 'ВКонтакте'];

    private const PENDING = 'social_pending';

    public function redirect(string $provider): SymfonyRedirect|RedirectResponse
    {
        if (! config("services.{$provider}.client_id")) {
            return redirect()->route('login')->withErrors(['email' => 'Вход через '.self::NAMES[$provider].' пока не настроен.']);
        }

        $driver = $this->driver($provider);

        if ($provider === 'yandex') {
            $driver->scopes(['login:email', 'login:info']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $name = self::NAMES[$provider];

        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors(['email' => "Вход через {$name} отменён."]);
        }

        try {
            $social = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            Log::warning('Вход через соцсеть не удался', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return redirect()->route('login')->withErrors(['email' => "Не удалось войти через {$name}. Попробуйте ещё раз."]);
        }

        $account = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', (string) $social->getId())->first();

        if ($account !== null) {
            return $this->login($request, $account->user);
        }

        $email = Str::lower(trim((string) $social->getEmail()));
        $existing = $email !== '' ? User::query()->where('email', $email)->first() : null;

        if ($existing !== null) {
            $existing->socialAccounts()->updateOrCreate(['provider' => $provider], ['provider_user_id' => (string) $social->getId(), 'email' => $email]);

            return $this->login($request, $existing);
        }

        $request->session()->put(self::PENDING, ['provider' => $provider, 'id' => (string) $social->getId(), 'email' => $email, ...$this->names($social)]);

        return redirect()->route('social.complete');
    }

    public function complete(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/SocialComplete', [
            'provider' => self::NAMES[$pending['provider']],
            'firstName' => $pending['first_name'],
            'lastName' => $pending['last_name'],
            'email' => $pending['email'],
        ]);
    }

    public function store(SocialCompleteRequest $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending)) {
            return redirect()->route('login');
        }

        // email от провайдера подтверждён им, поменять его на этом экране нельзя
        $email = $pending['email'] !== '' ? $pending['email'] : Str::lower(trim($request->validated('email')));

        if (User::query()->where('email', $email)->exists()) {
            return back()->withErrors(['email' => 'Аккаунт с этим email уже есть. Войдите по паролю.']);
        }

        $user = User::create([
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'email' => $email,
            'password' => Str::random(40),
            'privacy_consent' => true,
            'oferta_consent' => true,
            'newsletter_consent' => $request->boolean('newsletter_consent'),
        ]);

        if ($pending['email'] !== '') {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->socialAccounts()->create(['provider' => $pending['provider'], 'provider_user_id' => $pending['id'], 'email' => $email]);
        $request->session()->forget(self::PENDING);

        event(new Registered($user));
        app(SenderMailService::class)->sendWelcomeEmail($user);

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('message', 'Регистрация прошла успешно. Добро пожаловать!');
    }

    /**
     * Адрес возврата берётся из маршрута, а не из APP_URL: он должен в точности совпадать с указанным у Яндекса и ВК.
     */
    private function driver(string $provider): mixed
    {
        return Socialite::driver($provider)->redirectUrl(route('social.callback', $provider));
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    private function names(ProviderUser $social): array
    {
        $raw = method_exists($social, 'getRaw') ? (array) $social->getRaw() : [];
        $first = trim((string) ($raw['first_name'] ?? ''));
        $last = trim((string) ($raw['last_name'] ?? ''));

        if ($first === '' && $social->getName()) {
            [$first, $last] = array_pad(explode(' ', trim((string) $social->getName()), 2), 2, '');
        }

        return ['first_name' => $first, 'last_name' => $last];
    }
}
