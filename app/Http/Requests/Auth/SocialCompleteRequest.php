<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pending = $this->session()->get('social_pending');
        $needsEmail = ! is_array($pending) || ($pending['email'] ?? '') === '';

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => $needsEmail ? ['required', 'string', 'lowercase', 'email', 'max:255'] : ['nullable'],
            'privacy_consent' => ['accepted'],
            'oferta_consent' => ['accepted'],
            'newsletter_consent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'privacy_consent.accepted' => 'Нужно согласие на обработку персональных данных.',
            'oferta_consent.accepted' => 'Нужно согласие с условиями оферты.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
