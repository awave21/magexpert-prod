<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
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
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,}$/i',
                'unique:'.config('sender.connection').'.sender_domains,domain',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Укажите домен',
            'domain.regex' => 'Домен указан некорректно, например example.ru',
            'domain.unique' => 'Этот домен уже добавлен',
        ];
    }
}
