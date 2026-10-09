<?php

namespace App\Sender\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
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
            'template' => ['required'],
            'to' => ['required', 'email'],
            'from' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template.required' => 'Укажите шаблон письма',
            'to.required' => 'Укажите адрес получателя',
            'to.email' => 'Адрес получателя некорректен',
            'from.email' => 'Адрес отправителя некорректен',
        ];
    }
}
