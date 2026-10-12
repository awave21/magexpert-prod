<?php

namespace App\Sender\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpsertListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название базы',
            'name.string' => 'Название базы должно быть строкой',
            'name.max' => 'Название слишком длинное: не больше 120 символов',
            'description.string' => 'Описание должно быть строкой',
            'description.max' => 'Описание слишком длинное: не больше 500 символов',
        ];
    }
}
