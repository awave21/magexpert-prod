<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateFolderRequest extends FormRequest
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
                Rule::unique(config('sender.connection', 'sender').'.sender_template_folders', 'name')
                    ->where('organization_id', $this->attributes->get('sender_organization')?->id)
                    ->ignore($this->route('folder')),
            ],
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
            'name.required' => 'Укажите название папки',
            'name.unique' => 'Папка с таким названием уже есть',
            'name.max' => 'Название слишком длинное',
        ];
    }
}
