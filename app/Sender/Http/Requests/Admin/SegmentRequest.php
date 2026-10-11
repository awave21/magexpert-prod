<?php

namespace App\Sender\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
