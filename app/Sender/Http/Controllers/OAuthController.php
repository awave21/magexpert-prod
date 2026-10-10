<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Services\SocialLoginService;
use App\Sender\Support\SenderUi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Переход на Яндекс ID или VK ID и возврат обратно в админку Sender.
 */
class OAuthController extends Controller
{
    private const NAMES = ['yandex' => 'Яндекс', 'vkid' => 'ВКонтакте'];

    public function redirect(string $provider): SymfonyRedirect|RedirectResponse
    {
        if (! config("services.{$provider}.client_id")) {
            return $this->back('login', ['error' => 'Вход через '.self::NAMES[$provider].' пока не настроен']);
        }

        $driver = $this->driver($provider);

        if ($provider === 'yandex') {
            $driver->scopes(['login:email', 'login:info']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider, SocialLoginService $social): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->back('login', ['error' => 'Вход через '.self::NAMES[$provider].' отменён']);
        }

        try {
            $user = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            Log::warning('Sender: вход через соцсеть не удался', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return $this->back('login', ['error' => 'Не удалось войти через '.self::NAMES[$provider].'. Попробуйте ещё раз']);
        }

        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        $name = trim(($raw['first_name'] ?? '').' '.($raw['last_name'] ?? '')) ?: trim((string) $user->getName());

        $result = $social->handle([
            'provider' => $provider,
            'id' => (string) $user->getId(),
            'email' => Str::lower(trim((string) $user->getEmail())),
            'name' => $name,
        ]);

        return $this->back($result['type'] === 'login' ? 'login' : 'register', ['oauth' => $result['code']]);
    }

    private function driver(string $provider): mixed
    {
        return Socialite::driver($provider)->redirectUrl(SenderUi::url("oauth/{$provider}/callback"));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function back(string $page, array $query): RedirectResponse
    {
        return redirect()->away(SenderUi::url($page).'?'.http_build_query($query));
    }
}
