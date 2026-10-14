<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Сегмент: название, база (только при создании: значения условий берутся из неё) и условия.
 */
class SaveSegmentRequest extends FormRequest
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
        $organizationId = $this->attributes->get('sender_organization')?->id;
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'list_id' => $creating
                ? ['required', 'integer', Rule::exists(config('sender.connection', 'sender').'.sender_lists', 'id')->where('organization_id', $organizationId)]
                : ['prohibited'],
            ...Segment::conditionRules(),
            'conditions' => ['required', 'array', 'min:1', 'max:'.Segment::MAX_CONDITIONS],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (Segment::conditionErrors((array) $this->input('conditions', [])) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Назовите сегмент',
            'list_id.required' => 'Выберите базу',
            'list_id.exists' => 'Такой базы нет',
            'list_id.prohibited' => 'Базу сегмента поменять нельзя: создайте новый сегмент',
            'conditions.required' => 'Добавьте хотя бы одно условие',
            'conditions.min' => 'Добавьте хотя бы одно условие',
            'conditions.*.days.required_if' => 'Укажите число дней',
            'conditions.*.days.min' => 'Период не меньше дня',
            'conditions.*.field.required_if' => 'Выберите поле',
            'conditions.*.values.required_if' => 'Выберите хотя бы одно значение',
            'conditions.*.tags.required_if' => 'Выберите хотя бы один тег',
            'conditions.*.tags.*.regex' => 'Такого тега нет',
        ];
    }
}
