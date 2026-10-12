<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Сопоставление колонок приходит из формы с файлом строкой JSON.
     */
    protected function prepareForValidation(): void
    {
        // нечитаемая строка (не JSON или не объект) остаётся строкой: правило array вернёт понятную ошибку
        if (is_string($this->input('mapping'))) {
            $decoded = json_decode($this->input('mapping'), true);

            if (json_last_error() === JSON_ERROR_NONE && ($decoded === null || is_array($decoded))) {
                $this->merge(['mapping' => $decoded]);
            }
        }

        // Laravel принимает как boolean только 1/0/true/false: "true"/"false" из FormData приводятся заранее
        if (is_string($this->input('consent')) && in_array(strtolower($this->input('consent')), ['true', 'false'], true)) {
            $this->merge(['consent' => strtolower($this->input('consent')) === 'true']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'max:20480', 'extensions:csv,txt'],
            'text' => ['nullable', 'string', 'max:2000000', 'required_without:file'],
            'mapping' => ['nullable', 'array'],
            // пустое сопоставление ({} или []) — тоже сопоставление без колонки адреса
            'mapping.email' => [Rule::requiredIf(fn (): bool => is_array($this->input('mapping'))), 'integer', 'min:0', 'max:200'],
            'mapping.name' => ['nullable', 'integer', 'min:0', 'max:200', 'different:mapping.email'],
            'mapping.header' => ['boolean'],
            'mapping.fields' => ['array', 'max:50'],
            'mapping.fields.*' => ['string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            // есть ли у адресов согласие на рассылку; без него новые адреса получат письмо подтверждения
            'consent' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: int, name?: ?int, fields?: array<int|string, string>, header?: bool}|null
     */
    public function mapping(): ?array
    {
        return $this->validated('mapping');
    }

    /**
     * Не передано — $default (у базы без обязательного подтверждения согласие есть, как и раньше).
     * Из формы с файлом приходит строкой "1"/"0"/"true"/"false".
     */
    public function consent(bool $default = true): bool
    {
        return filter_var($this->validated('consent', $default), FILTER_VALIDATE_BOOLEAN);
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
            'mapping.array' => 'Не удалось прочитать сопоставление колонок',
            'mapping.email.required' => 'Выберите колонку с адресом почты',
            'mapping.name.different' => 'Имя и адрес не могут быть в одной колонке',
            'mapping.fields.*.regex' => 'Название переменной: латиница, цифры и _, начинается с буквы',
            'mapping.fields.*.distinct' => 'Две колонки с одним названием переменной',
            'consent.boolean' => 'Укажите, есть ли согласие на рассылку',
        ];
    }
}
