<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
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
            'file' => ['nullable', 'file', 'max:20480', 'extensions:csv,txt'],
            'text' => ['nullable', 'string', 'max:2000000', 'required_without:file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Нужен файл CSV. В Excel: «Файл → Сохранить как → CSV»',
            'file.max' => 'Файл больше 20 МБ: разделите его на части',
            'text.required_without' => 'Выберите файл или вставьте адреса',
        ];
    }
}
