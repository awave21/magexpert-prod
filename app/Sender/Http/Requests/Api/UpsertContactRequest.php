<?php

namespace App\Sender\Http\Requests\Api;

use App\Sender\Models\Contact;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Анкета подписчика базы из внешней системы.
 *
 * Базовые поля: email (обязательное), name, phone, city. Дополнительные необязательные атрибуты передаются
 * объектом attributes: они становятся переменными письма и полями базы. Прежний объект data принимается как
 * синоним attributes. Плюс теги и согласие на рассылку (subscribed).
 * Те же правила проверяют каждую строку пакетной загрузки.
 */
class UpsertContactRequest extends FormRequest
{
    public const MAX_FIELDS = 50;

    public const MAX_VALUE_LENGTH = 1000;

    public const MAX_TAGS_PER_REQUEST = 20;

    /** Базовые поля анкеты: в attributes их передавать нельзя, у каждого своё свойство */
    public const BASE_FIELDS = ['email', 'name', 'phone', 'city'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        // PUT /lists/{list}/contacts/{email}: адрес берётся из пути, в теле его можно не передавать
        if (($routeEmail = $this->pathEmail()) !== null && blank($input['email'] ?? null)) {
            $input['email'] = $routeEmail;
        }

        $this->replace(self::normalize($input));
    }

    /**
     * Проверенные данные в виде, который понимает сервис: базовые поля phone и city и объект attributes
     * сводятся в data (переменные письма), прежний data остаётся синонимом attributes.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = self::applyProfile(parent::validated());

        return $key === null ? $validated : data_get($validated, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applyProfile(array $validated): array
    {
        $touched = array_key_exists('data', $validated) || array_key_exists('attributes', $validated)
            || array_key_exists('phone', $validated) || array_key_exists('city', $validated);

        if (! $touched) {
            return $validated;
        }

        $data = array_merge((array) ($validated['data'] ?? []), (array) ($validated['attributes'] ?? []));

        foreach (['phone', 'city'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        unset($validated['attributes'], $validated['phone'], $validated['city']);
        $validated['data'] = $data;

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = self::contactRules();

        if (($routeEmail = $this->pathEmail()) !== null) {
            $rules['email'][] = function (string $attribute, mixed $value, Closure $fail) use ($routeEmail): void {
                if (is_string($value) && mb_strtolower(trim($value)) !== $routeEmail) {
                    $fail('Адрес в теле запроса не совпадает с адресом в пути');
                }
            };
        }

        return $rules;
    }

    /**
     * Адрес подписчика из пути запроса (PUT /lists/{list}/contacts/{email}) в нижнем регистре.
     */
    private function pathEmail(): ?string
    {
        $email = $this->route('email');

        return is_string($email) ? mb_strtolower(trim(rawurldecode($email))) : null;
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

        if (is_array($input['tags'] ?? null)) {
            $input['tags'] = Contact::normalizeTags($input['tags']);
        }

        if (is_string($input['phone'] ?? null)) {
            $input['phone'] = self::normalizePhone($input['phone']);
        }

        if (is_string($input['city'] ?? null)) {
            $city = trim($input['city']);
            $input['city'] = $city === '' ? null : $city;
        }

        return $input;
    }

    /**
     * Телефон без оформления: только цифры и ведущий +. Российские 8XXXXXXXXXX и 7XXXXXXXXXX приводятся к +7XXXXXXXXXX.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $plus = str_starts_with(trim($phone), '+') ? '+' : '';
        $digits = (string) preg_replace('/\D+/', '', $phone);

        // пустая строка очищает телефон; строка без цифр остаётся как есть и не пройдёт проверку формата
        if ($digits === '') {
            return trim($phone) === '' ? null : trim($phone);
        }

        if ($plus === '' && strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            return '+7'.substr($digits, 1);
        }

        return $plus.$digits;
    }

    /**
     * Правила набора атрибутов (attributes и прежний data): ключи и значения одинаковые.
     *
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    private static function attributeRules(bool $rejectBaseFields): array
    {
        $keys = function (string $attribute, mixed $value, Closure $fail) use ($rejectBaseFields): void {
            foreach (array_keys((array) $value) as $key) {
                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                    $fail('Название переменной «'.$key.'»: латиница в нижнем регистре, цифры и _, начинается с буквы, до 50 символов');

                    return;
                }

                if ($rejectBaseFields && in_array($key, self::BASE_FIELDS, true)) {
                    $fail('«'.$key.'» это базовое поле анкеты: передайте его отдельным свойством, а не в attributes');

                    return;
                }
            }
        };
        $values = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! is_scalar($value)) {
                $fail('Значение переменной должно быть строкой, числом, true/false или null');
            } elseif (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $fail('Значение переменной слишком длинное: не больше '.self::MAX_VALUE_LENGTH.' символов');
            }
        };

        return [['sometimes', 'nullable', 'array', 'max:'.self::MAX_FIELDS, $keys], [$values]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contactRules(): array
    {
        [$dataRules, $dataValueRules] = self::attributeRules(false);
        [$attributeRules, $attributeValueRules] = self::attributeRules(true);

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:250'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'attributes' => $attributeRules,
            'attributes.*' => $attributeValueRules,
            'data' => $dataRules,
            'data.*' => $dataValueRules,
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_TAGS_PER_REQUEST],
            'tags.*' => ['string', 'regex:'.Contact::TAG_PATTERN],
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
            'phone.regex' => 'Телефон: от 10 до 15 цифр, можно с ведущим +',
            'city.max' => 'Город слишком длинный: не больше 120 символов',
            'attributes.array' => 'Дополнительные атрибуты передаются объектом {"ключ": "значение"}',
            'attributes.max' => 'Не больше '.self::MAX_FIELDS.' дополнительных атрибутов',
            'data.array' => 'Переменные передаются объектом {"ключ": "значение"}',
            'data.max' => 'Не больше '.self::MAX_FIELDS.' переменных у подписчика',
            'tags.array' => 'Теги передаются списком ["event-63", …]',
            'tags.max' => 'Не больше '.self::MAX_TAGS_PER_REQUEST.' тегов за один запрос',
            'tags.*.string' => 'Тег — строка',
            'tags.*.regex' => 'Тег: буквы, цифры, пробел и . _ : , - ( ) / +, начинается с буквы или цифры, до 120 символов',
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

        return [self::applyProfile($validator->validated()), []];
    }
}
