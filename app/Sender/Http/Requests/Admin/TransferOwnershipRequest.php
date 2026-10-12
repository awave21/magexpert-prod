<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransferOwnershipRequest extends FormRequest
{
    public const PASSWORD_NOT_SET = 'Сначала задайте пароль в профиле';

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
            'user_id' => ['required', 'integer'],
            'current_password' => $this->ownerHasPassword() ? ['required', 'string'] : ['nullable'],
        ];
    }

    /**
     * Владение передаётся только с паролем: после входа через Яндекс или ВКонтакте его сначала задают в профиле.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->ownerHasPassword()) {
                $validator->errors()->add('current_password', self::PASSWORD_NOT_SET);
            }
        });
    }

    public function ownerHasPassword(): bool
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
            'user_id.required' => 'Выберите, кому передать владение',
            'user_id.integer' => 'Выберите, кому передать владение',
            'current_password.required' => 'Укажите свой текущий пароль',
        ];
    }
}
