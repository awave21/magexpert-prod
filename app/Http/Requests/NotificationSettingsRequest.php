<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'newsletter_consent' => ['required', 'boolean'],
            'site_notifications' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'newsletter_consent.required' => 'Укажите, присылать ли письма на почту.',
            'site_notifications.required' => 'Укажите, показывать ли уведомления на сайте.',
        ];
    }
}
