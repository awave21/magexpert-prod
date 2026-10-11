<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Webhook;
use App\Sender\Support\PublicAddress;
use Closure;
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
            'url' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'url:https', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! app(PublicAddress::class)->allows($value)) {
                    $fail('Адрес должен вести в интернет: внутренние адреса и localhost запрещены');
                }
            }],
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
