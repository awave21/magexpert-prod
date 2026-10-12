<?php

namespace App\Sender\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Пакет подписчиков: сам пакет должен быть списком до 1000 строк, а каждая строка проверяется отдельно
 * (ContactUpsertService применяет корректные строки и сообщает об ошибках в остальных).
 */
class BatchContactsRequest extends FormRequest
{
    public const MAX_ROWS = 1000;

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
            'contacts' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ROWS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contacts.required' => 'Передайте подписчиков в поле contacts',
            'contacts.array' => 'contacts — список подписчиков',
            'contacts.list' => 'contacts — список подписчиков',
            'contacts.min' => 'Передайте хотя бы одного подписчика',
            'contacts.max' => 'Не больше '.self::MAX_ROWS.' подписчиков за один запрос',
        ];
    }

    /**
     * @return list<mixed>
     */
    public function contacts(): array
    {
        return array_values((array) $this->input('contacts'));
    }
}
