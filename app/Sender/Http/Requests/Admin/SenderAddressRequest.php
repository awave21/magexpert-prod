<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Domain;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SenderAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->attributes->get('sender_organization')?->id;
        $creating = $this->isMethod('post');

        return [
            // при изменении адрес не трогаем, меняется только имя
            'email' => ! $creating ? ['exclude'] : [
                'required', 'email', 'max:190',
                Rule::unique(config('sender.connection').'.sender_addresses', 'email')->where('organization_id', $organizationId),
                function (string $attribute, mixed $value, Closure $fail) use ($organizationId): void {
                    $exists = Domain::query()->where('organization_id', $organizationId)
                        ->where('domain', Str::after((string) $value, '@'))->exists();

                    if (! $exists) {
                        $fail('Сначала добавьте домен этого адреса в разделе «Домены»');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Укажите адрес',
            'email.email' => 'Адрес указан некорректно',
            'email.unique' => 'Такой адрес уже добавлен',
            'name.required' => 'Укажите имя отправителя',
        ];
    }
}
