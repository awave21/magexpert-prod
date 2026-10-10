<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCompleteRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SenderMailService;
use App\Services\SocialAccounts;
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
    private const NAMES = SocialAccounts::NAMES;

    public const PENDING = 'social_pending';

    /**
     * Пользователь уже вошёл и привязывает Яндекс или ВКонтакте: куда вернуть его после ответа провайдера.
     */
    private const LINKING = 'social_linking';

    public function __construct(private readonly SocialAccounts $accounts) {}

    public function redirect(string $provider): SymfonyRedirect|RedirectResponse
    {
        if (! config("services.{$provider}.client_id")) {
            return redirect()->route('login')->withErrors(['email' => 'Вход через '.self::NAMES[$provider].' пока не настроен.']);
        }

        $driver = $this->driver($provider);

        // телефон просим, только если доступ к нему включён в приложении у Яндекса или ВКонтакте
        $phone = (bool) config("services.{$provider}.phone");

        if ($provider === 'yandex') {
            $driver->scopes(array_merge(['login:email', 'login:info'], $phone ? ['login:default_phone'] : []));
        } elseif ($phone) {
            $driver->scopes(['phone']);
        }

        return $driver->redirect();
    }

    /**
     * Привязка из профиля: после возврата от провайдера аккаунт привяжется к текущему пользователю.
     */
    public function link(Request $request, string $provider): SymfonyRedirect|RedirectResponse
    {
        // возвращаем туда, откуда нажали кнопку (сводка, «Вход и безопасность»), а не на чужую страницу
        $previous = url()->previous(route('cabinet.security'));
        $back = str_starts_with($previous, url('/')) && ! str_contains($previous, '/auth/') ? $previous : route('cabinet.security');
        $request->session()->put(self::LINKING, $back);

        return $this->redirect($provider);
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $request->user()->socialAccounts()->where('provider', $provider)->delete();

        return back()->with('message', self::NAMES[$provider].' отвязан. Входить можно по email и паролю, забытый пароль восстанавливается на странице входа.');
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $name = self::NAMES[$provider];
        // вошедший пользователь здесь всегда привязывает аккаунт, даже если сессия потеряла адрес возврата
        $back = $request->session()->pull(self::LINKING);
        $linking = $request->user() !== null;
        $back = is_string($back) ? $back : route('cabinet.security');
        $fail = $linking ? redirect()->to($back) : redirect()->route('login');

        if ($request->filled('error')) {
            return $linking
                ? $fail->with('error', "Привязка {$name} отменена.")
                : $fail->withErrors(['email' => "Вход через {$name} отменён."]);
        }

        try {
            $social = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            Log::warning('Вход через соцсеть не удался', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return $linking
                ? $fail->with('error', "Не удалось связаться с {$name}. Попробуйте ещё раз.")
                : $fail->withErrors(['email' => "Не удалось войти через {$name}. Попробуйте ещё раз."]);
        }

        $profile = $this->profile($provider, $social);

        if ($linking) {
            return $this->linkCurrent($request->user(), $profile, $back);
        }

        $account = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $profile['id'])->first();
        $user = $account?->user ?? ($profile['email'] !== '' ? User::query()->where('email', $profile['email'])->first() : null);

        if ($user !== null) {
            $this->accounts->link($user, $profile);
            $this->accounts->applyPhone($user, $profile['phone']);

            return $this->login($request, $user);
        }

        $request->session()->put(self::PENDING, $profile);

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

        $this->accounts->link($user, [...$pending, 'email' => $email]);
        $this->accounts->applyPhone($user, $pending['phone'] ?? null);
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

    private function linkCurrent(User $user, array $profile, string $back): RedirectResponse
    {
        $name = self::NAMES[$profile['provider']];

        if (! $this->accounts->link($user, $profile)) {
            return redirect()->to($back)->with('error', "Этот аккаунт {$name} уже привязан к другому профилю на сайте.");
        }

        $status = $this->accounts->applyPhone($user, $profile['phone']);
        $key = in_array($status, [SocialAccounts::PHONE_FILLED, SocialAccounts::PHONE_VERIFIED], true) ? 'message' : 'error';

        return redirect()->to($back)->with($key, $this->accounts->message($profile['provider'], $status));
    }

    /**
     * @return array{provider: string, id: string, email: string, phone: ?string, first_name: string, last_name: string}
     */
    private function profile(string $provider, ProviderUser $social): array
    {
        $raw = method_exists($social, 'getRaw') ? (array) $social->getRaw() : [];

        return [
            'provider' => $provider,
            'id' => (string) $social->getId(),
            'email' => Str::lower(trim((string) $social->getEmail())),
            'phone' => $this->accounts->phoneFrom($provider, $raw),
            ...$this->names($social),
        ];
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
