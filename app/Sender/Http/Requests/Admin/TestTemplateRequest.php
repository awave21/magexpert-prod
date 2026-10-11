<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TestTemplateRequest extends FormRequest
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
            'to' => ['required', 'email', 'max:190'],
            'data' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required' => 'Укажите адрес для теста',
            'to.email' => 'Адрес указан некорректно',
        ];
    }
}
