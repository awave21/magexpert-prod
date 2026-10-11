<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVideoProgressRequest;
use App\Models\Event;
use App\Models\VideoProgress;
use Illuminate\Http\Response;

class VideoProgressController extends Controller
{
    /**
     * Плеер на странице записи присылает позицию раз в 10 секунд, на паузе, при перемотке и закрытии вкладки.
     */
    public function store(StoreVideoProgressRequest $request, Event $event): Response
    {
        $validated = $request->validated();
        $duration = $validated['duration'] ?? null;
        $position = $duration ? min($validated['position'], $duration) : $validated['position'];

        $progress = VideoProgress::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'event_id' => $event->id,
            'video_key' => $validated['video_key'],
        ]);

        $progress->position = $position;
        $progress->duration = $duration ?? $progress->duration;

        if ($progress->completed_at === null && $progress->duration && $position >= $progress->duration * VideoProgress::COMPLETED_SHARE) {
            $progress->completed_at = now();
        }

        // Время обновляем всегда: по нему плейлист понимает, какой ролик смотрели последним
        $progress->updated_at = now();
        $progress->save();

        return response()->noContent();
    }
}
