<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Свои данные в профиле. Email здесь не меняется: по нему входят и к нему привязывается вход через Яндекс и ВКонтакте.
 */
class ProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя',
            'name.min' => 'Имя слишком короткое',
            'name.max' => 'Имя не длиннее 120 символов',
            'email.prohibited' => 'Email изменить нельзя',
        ];
    }
}
