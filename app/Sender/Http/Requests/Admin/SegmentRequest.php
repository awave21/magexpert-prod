<?php

namespace App\Sender\Http\Requests\Admin;

use App\Sender\Models\Contact;
use App\Sender\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SegmentRequest extends FormRequest
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
            'segment' => ['nullable', 'array'],
            'segment.active_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'segment.field' => ['nullable', 'regex:/^[a-z0-9_]{1,64}$/'],
            'segment.values' => ['nullable', 'array', 'max:50'],
            'segment.values.*' => ['string', 'max:200'],
            'segment.tags' => ['nullable', 'array', 'max:20'],
            'segment.tags.*' => ['string', 'regex:'.Contact::TAG_PATTERN],
            ...Segment::conditionRules(),
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
}
