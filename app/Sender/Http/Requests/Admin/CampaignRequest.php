<?php

namespace App\Sender\Http\Requests\Admin;

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
            'list_id' => ['nullable', 'integer', Rule::exists($connection.'.sender_lists', 'id')->where('organization_id', $organizationId)],
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
        ];
    }
}
