<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignRequest extends FormRequest
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
        $connection = config('sender.connection', 'sender');
        $organizationId = $this->attributes->get('sender_organization')?->id;

        return [
            'name' => ['required', 'string', 'max:200'],
            'template_id' => ['nullable', 'integer', Rule::exists($connection.'.sender_templates', 'id')->where('organization_id', $organizationId)],
            'track' => ['sometimes', 'boolean'],
            'list_id' => ['nullable', 'integer', Rule::exists($connection.'.sender_lists', 'id')->where('organization_id', $organizationId)],
            'send_mode' => ['sometimes', Rule::in([Campaign::MODE_NOW, Campaign::MODE_DRIP])],
            'batch_size' => ['nullable', 'required_if:send_mode,'.Campaign::MODE_DRIP, 'integer', 'min:1', 'max:100000'],
            'batch_interval' => ['nullable', 'required_if:send_mode,'.Campaign::MODE_DRIP, 'integer', 'min:1', 'max:1440'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'window_start' => ['nullable', 'date_format:H:i', 'required_with:window_end'],
            'window_end' => ['nullable', 'date_format:H:i', 'required_with:window_start', 'after:window_start'],
            'window_days' => ['nullable', 'array', 'min:1'],
            'window_days.*' => ['integer', 'between:1,7', 'distinct'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Назовите рассылку',
            'template_id.exists' => 'Такого контента нет',
            'list_id.exists' => 'Такой базы нет',
            'batch_size.required_if' => 'Укажите, сколько писем отправлять за раз',
            'batch_size.min' => 'Хотя бы одно письмо за раз',
            'batch_interval.required_if' => 'Укажите паузу между пачками',
            'batch_interval.min' => 'Пауза не меньше минуты',
            'batch_interval.max' => 'Пауза не больше суток',
            'daily_limit.min' => 'Лимит в сутки не меньше одного письма',
            'window_start.date_format' => 'Время в формате ЧЧ:ММ',
            'window_end.date_format' => 'Время в формате ЧЧ:ММ',
            'window_start.required_with' => 'Укажите начало окна',
            'window_end.required_with' => 'Укажите конец окна',
            'window_end.after' => 'Конец окна должен быть позже начала',
            'window_days.min' => 'Выберите хотя бы один день',
            'scheduled_at.after' => 'Время старта уже прошло',
        ];
    }

    /**
     * Для отправки сразу настройки пачек не нужны: сбрасываем их, чтобы не путали.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key === null && ($data['send_mode'] ?? null) === Campaign::MODE_NOW) {
            $data = [...$data, 'batch_size' => null, 'batch_interval' => null, 'daily_limit' => null, 'window_start' => null, 'window_end' => null, 'window_days' => null];
        }

        return $data;
    }
}
