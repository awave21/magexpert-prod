<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ProfilePasswordRequest extends FormRequest
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
        // после регистрации через Яндекс или ВКонтакте пароля нет: его задают без текущего
        if (! $this->hasPassword()) {
            return [
                'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            ];
        }

        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed', 'different:current_password'],
        ];
    }

    public function hasPassword(): bool
    {
        $user = $this->attributes->get('sender_user');

        return ! $user instanceof User || $user->hasPassword();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Укажите текущий пароль',
            'password.required' => 'Укажите новый пароль',
            'password.min' => 'Пароль не короче 8 символов',
            'password.max' => 'Пароль не длиннее 128 символов',
            'password.confirmed' => 'Пароли не совпадают',
            'password.different' => 'Новый пароль совпадает с текущим',
        ];
    }
}
