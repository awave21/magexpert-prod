<?php

namespace App\Sender\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        $this->merge(['idempotency_key' => $key === null ? null : trim($key)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'template' => ['required'],
            'to' => ['required', 'email'],
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
            'idempotency_key.max' => 'Idempotency-Key: не больше 255 символов',
            'idempotency_key.regex' => 'Idempotency-Key: только видимые символы ASCII без пробелов',
        ];
    }
}
