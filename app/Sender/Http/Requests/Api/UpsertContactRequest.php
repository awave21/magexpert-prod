<?php

namespace App\Sender\Http\Requests\Api;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Подписчик базы из внешней системы: адрес, имя, переменные для писем и согласие на рассылку.
 * Те же правила проверяют каждую строку пакетной загрузки.
 */
class UpsertContactRequest extends FormRequest
{
    public const MAX_FIELDS = 50;

    public const MAX_VALUE_LENGTH = 1000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(self::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::contactRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::contactMessages();
    }

    /**
     * Адрес приводится к нижнему регистру, имя обрезается по краям.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public static function normalize(array $input): array
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        if (is_string($input['name'] ?? null)) {
            $name = trim($input['name']);
            $input['name'] = $name === '' ? null : $name;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    public static function contactRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:250'],
            'data' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_FIELDS, function (string $attribute, mixed $value, Closure $fail): void {
                foreach (array_keys((array) $value) as $key) {
                    if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                        $fail('Название переменной «'.$key.'»: латиница в нижнем регистре, цифры и _, начинается с буквы, до 50 символов');

                        return;
                    }
                }
            }],
            'data.*' => [function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! is_scalar($value)) {
                    $fail('Значение переменной должно быть строкой, числом, true/false или null');
                } elseif (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                    $fail('Значение переменной слишком длинное: не больше '.self::MAX_VALUE_LENGTH.' символов');
                }
            }],
            'subscribed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contactMessages(): array
    {
        return [
            'email.required' => 'Укажите email',
            'email.string' => 'Неверный email',
            'email.email' => 'Неверный email',
            'email.max' => 'Email слишком длинный',
            'name.string' => 'Имя должно быть строкой',
            'name.max' => 'Имя слишком длинное: не больше 250 символов',
            'data.array' => 'Переменные передаются объектом {"ключ": "значение"}',
            'data.max' => 'Не больше '.self::MAX_FIELDS.' переменных у подписчика',
            'subscribed.boolean' => 'subscribed — true или false',
        ];
    }

    /**
     * Проверяет одну строку пакета: проверенные данные или ошибки по полям.
     *
     * @param  array<mixed>  $input
     * @return array{0: array{email: string, name?: string|null, data?: array<string, mixed>|null, subscribed?: bool}|null, 1: array<string, list<string>>}
     */
    public static function validateRow(array $input): array
    {
        $validator = Validator::make(self::normalize($input), self::contactRules(), self::contactMessages());

        if ($validator->fails()) {
            return [null, $validator->errors()->toArray()];
        }

        return [$validator->validated(), []];
    }
}
