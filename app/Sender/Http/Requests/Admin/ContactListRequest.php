<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Services\SubscriberSources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactListRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique(config('sender.connection', 'sender').'.sender_lists', 'name')
                    ->where('organization_id', $this->attributes->get('sender_organization')?->id)
                    ->ignore($this->route('list')),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            // источник задаётся только при создании базы
            'source' => $this->isMethod('POST')
                ? ['nullable', 'string', Rule::in(array_keys(app(SubscriberSources::class)->available($this->attributes->get('sender_organization'))))]
                : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название базы',
            'name.unique' => 'База с таким названием уже есть',
            'name.max' => 'Название слишком длинное',
            'description.max' => 'Описание слишком длинное',
            'source.in' => 'Такого источника подписчиков нет',
            'source.prohibited' => 'Источник базы изменить нельзя',
        ];
    }
}
