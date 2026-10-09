<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateRequest extends FormRequest
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
        $organizationId = $this->attributes->get('sender_organization')->id;
        $template = $this->route('template');

        return [
            'slug' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique(config('sender.connection').'.sender_templates', 'slug')
                    ->where('organization_id', $organizationId)
                    ->ignore($template),
            ],
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'body_text' => ['nullable', 'string'],
            'editor' => ['sometimes', 'string', Rule::in(['html', 'blocks'])],
            'design' => ['nullable', 'array', 'required_if:editor,blocks'],
            'design.blocks' => ['required_with:design', 'array', 'max:200'],
            'design.settings' => ['nullable', 'array'],
            'folder_id' => [
                'nullable', 'integer',
                Rule::exists(config('sender.connection').'.sender_template_folders', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.required' => 'Укажите идентификатор шаблона',
            'slug.alpha_dash' => 'Идентификатор может содержать только латиницу, цифры, дефис и подчёркивание',
            'slug.unique' => 'Шаблон с таким идентификатором уже есть',
            'name.required' => 'Укажите название',
            'subject.required' => 'Укажите тему письма',
            'folder_id.exists' => 'Такой папки нет',
            'body_html.required' => 'Укажите текст письма',
            'design.required_if' => 'Письмо из блоков не передано',
            'design.blocks.max' => 'В письме слишком много блоков',
        ];
    }
}
