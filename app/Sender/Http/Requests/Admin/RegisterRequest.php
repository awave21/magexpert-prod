<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
        return [
            'organization' => ['required', 'string', 'min:2', 'max:120'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(config('sender.connection', 'sender').'.sender_users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:128'],
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
            'name.required' => 'Укажите ваше имя',
            'name.min' => 'Имя слишком короткое',
            'email.required' => 'Укажите email',
            'email.email' => 'Email указан некорректно',
            'email.unique' => 'Аккаунт с таким email уже есть. Войдите или используйте другой адрес',
            'password.required' => 'Укажите пароль',
            'password.min' => 'Пароль не короче 8 символов',
        ];
    }
}
