<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Выберите файл',
            'file.mimes' => 'Подходят PNG, JPG, GIF или WebP',
            'file.max' => 'Файл больше 5 МБ',
        ];
    }
}
