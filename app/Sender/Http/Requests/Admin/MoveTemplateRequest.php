<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveTemplateRequest extends FormRequest
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
            'folder_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists(config('sender.connection').'.sender_template_folders', 'id')
                    ->where('organization_id', $this->attributes->get('sender_organization')?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'folder_id.present' => 'Укажите папку',
            'folder_id.exists' => 'Такой папки нет',
        ];
    }
}
