<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Http\Controllers\Admin\AuthController;
use App\Sender\Models\User;
use App\Sender\Services\SocialLoginService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OAuthRegisterRequest extends FormRequest
{
    /**
     * Самостоятельная регистрация включается в настройках (SENDER_REGISTRATION), до проверки полей.
     */
    public function authorize(): bool
    {
        return (bool) config('sender.registration');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(AuthController::REGISTRATION_CLOSED);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pending = app(SocialLoginService::class)->pending((string) $this->input('code'));
        $needsEmail = ($pending['email'] ?? '') === '';

        return [
            'code' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($pending): void {
                if ($pending === null) {
                    $fail('Ссылка устарела: войдите через Яндекс или ВКонтакте ещё раз');
                } elseif ($pending['email'] !== '' && User::query()->where('email', $pending['email'])->exists()) {
                    // почта из ВКонтакте не подтверждена: к чужому аккаунту её не привязываем и второй такой же не создаём
                    $fail('Аккаунт с этим email уже есть. Войдите по паролю');
                }
            }],
            'organization' => ['required', 'string', 'min:2', 'max:120'],
            'email' => $needsEmail
                ? ['required', 'email', 'max:190', Rule::unique(config('sender.connection', 'sender').'.sender_users', 'email')]
                : ['nullable'],
            'accept_policy' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organization.required' => 'Укажите название организации',
            'organization.min' => 'Название организации слишком короткое',
            'email.required' => 'Укажите email',
            'email.unique' => 'Аккаунт с таким email уже есть. Войдите по паролю',
            'accept_policy.accepted' => 'Нужно согласиться с политикой обработки персональных данных',
        ];
    }
}
