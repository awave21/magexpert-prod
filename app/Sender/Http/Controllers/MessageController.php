<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Http\Requests\StoreMessageRequest;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, MessageService $messages): JsonResponse
    {
        $organization = $this->organization($request);

        $template = $organization->templates()->where('slug', $request->string('template'))->first();

        if ($template === null) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        $message = $messages->send(
            $organization,
            $template,
            $request->string('to')->toString(),
            $request->string('from')->toString(),
            $request->input('from_name'),
            $request->input('data', []),
        );

        $status = $message->status === Message::STATUS_BLOCKED ? 422 : 202;

        return response()->json($this->payload($message), $status);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $message = $this->organization($request)->messages()->where('uuid', $uuid)->first();

        if ($message === null) {
            return response()->json(['message' => 'Сообщение не найдено'], 404);
        }

        return response()->json($this->payload($message));
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('sender_organization');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Message $message): array
    {
        return [
            'id' => $message->uuid,
            'status' => $message->status,
            'to' => $message->to_email,
            'subject' => $message->subject,
            'attempts' => $message->attempts,
            'error' => $message->error,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }
}
