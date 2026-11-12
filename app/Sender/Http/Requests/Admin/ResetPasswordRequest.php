<?php

namespace App\Sender\Http\Requests\Admin;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(),
            'token.required' => 'Откройте ссылку из письма',
            'token.size' => 'Ссылка недействительна. Запросите новую',
            'password.required' => 'Укажите новый пароль',
            'password.min' => 'Пароль не короче 8 символов',
            'password.max' => 'Пароль не длиннее 128 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ];
    }
}
