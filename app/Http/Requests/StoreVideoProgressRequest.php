<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreVideoProgressRequest extends FormRequest
{
    /**
     * Позицию сохраняем только тем, у кого есть доступ к записи мероприятия.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $this->user() !== null
            && $event instanceof Event
            && $event->is_active
            && $event->hasUserAccess($this->user());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'video_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'position' => ['required', 'integer', 'min:0', 'max:86400'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video_key.required' => 'Не указано, какое видео смотрели.',
            'video_key.regex' => 'Неверный ID видео.',
            'position.required' => 'Не указана позиция просмотра.',
        ];
    }
}
