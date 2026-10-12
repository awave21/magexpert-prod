<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canUseAssistant() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Напишите вопрос.',
            'question.min' => 'Вопрос слишком короткий.',
            'question.max' => 'Вопрос длиннее 1000 символов, сократите его.',
        ];
    }
}
