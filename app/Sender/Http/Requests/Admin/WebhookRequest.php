<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Webhook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookRequest extends FormRequest
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
            'url' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'url:https', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in(Webhook::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Укажите адрес вебхука',
            'url.url' => 'Нужен адрес https://',
            'events.*.in' => 'Такого события нет',
        ];
    }
}
