<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TestCampaignRequest extends FormRequest
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
            // чьи данные подставить в письмо: адрес подписчика из базы рассылки
            'contact_email' => ['nullable', 'email', 'max:190'],
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
            'contact_email.email' => 'Адрес подписчика указан некорректно',
        ];
    }
}
