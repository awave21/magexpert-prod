<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'email' => $creating
                ? ['required', 'email', 'max:190', Rule::unique(config('sender.connection', 'sender').'.sender_users', 'email')]
                : ['prohibited'],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
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
            'name.required' => 'Укажите имя',
            'email.required' => 'Укажите email',
            'email.email' => 'Email указан некорректно',
            'email.unique' => 'Пользователь с таким email уже есть',
            'role.in' => 'Выберите роль: администратор, редактор или просмотр',
        ];
    }
}
