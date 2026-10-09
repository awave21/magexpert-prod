<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Services\BaseVariables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariableRequest extends FormRequest
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
        $organization = $this->attributes->get('sender_organization');
        $ignore = $this->route('variable');

        return [
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::notIn(BaseVariables::keys()),
                Rule::unique(config('sender.connection', 'sender').'.sender_variables', 'key')
                    ->where('organization_id', $organization?->id)
                    ->ignore($ignore),
            ],
            'label' => ['required', 'string', 'max:120'],
            'default_value' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'Укажите ключ переменной',
            'key.regex' => 'Ключ: латиница в нижнем регистре, цифры и подчёркивание, начинается с буквы',
            'key.not_in' => 'Это имя занято базовой переменной',
            'key.unique' => 'Переменная с таким ключом уже есть',
            'key.max' => 'Ключ слишком длинный',
            'label.required' => 'Укажите название',
        ];
    }
}
