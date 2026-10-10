<?php

namespace App\Http\Requests;

use App\Models\CabinetBroadcast;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCabinetMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => filled($this->url) ? trim((string) $this->url) : null,
            'email' => filled($this->email) ? mb_strtolower(trim((string) $this->email)) : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::in([CabinetBroadcast::AUDIENCE_ALL, CabinetBroadcast::AUDIENCE_EVENT, CabinetBroadcast::AUDIENCE_USER])],
            'event_id' => ['nullable', 'required_if:audience,event', 'integer', 'exists:events,id'],
            'email' => ['nullable', 'required_if:audience,user', 'email', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! User::query()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                    $fail('Пользователя с таким email нет.');
                }
            }],
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:500'],
            // только ссылки внутри сайта: /my-events, /events/slug — без внешних адресов
            'url' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)[^\s]*$#'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audience.required' => 'Выберите, кому отправить.',
            'event_id.required_if' => 'Выберите мероприятие.',
            'email.required_if' => 'Укажите email пользователя.',
            'title.required' => 'Напишите заголовок.',
            'title.max' => 'Заголовок — не длиннее 120 символов.',
            'message.required' => 'Напишите текст сообщения.',
            'message.max' => 'Текст — не длиннее 500 символов.',
            'url.regex' => 'Ссылка должна вести на страницу сайта и начинаться с «/», например /my-events.',
        ];
    }
}
