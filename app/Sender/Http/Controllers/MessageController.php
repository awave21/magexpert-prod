<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Http\Requests\StoreMessageRequest;
use App\Sender\Models\Message;
use App\Sender\Models\Organization;
use App\Sender\Services\MessageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, MessageService $messages): JsonResponse
    {
        $organization = $this->organization($request);

        $key = (string) $request->input('template');
        $template = ctype_digit($key)
            ? $organization->templates()->whereKey((int) $key)->first()
            : $organization->templates()->where('slug', $key)->first();

        if ($template === null) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        $to = Str::lower(trim($request->string('to')->toString()));
        $idempotencyKey = $request->input('idempotency_key');

        // повтор запроса с тем же Idempotency-Key возвращает то же письмо и не создаёт второе
        if ($idempotencyKey !== null && ($existing = $this->byKey($organization, $idempotencyKey)) !== null) {
            return $this->replay($existing, $template->id, $to);
        }

        try {
            $message = $messages->send(
                $organization,
                $template,
                $to,
                $request->input('data', []),
                idempotencyKey: $idempotencyKey,
                requestId: $request->attributes->get('request_id'),
            );
        } catch (UniqueConstraintViolationException $exception) {
            // два одинаковых запроса пришли одновременно: победил первый, второй получает его письмо
            $existing = $idempotencyKey === null ? null : $this->byKey($organization, $idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $template->id, $to);
        }

        return response()->json($this->payload($message), $this->statusFor($message));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $message = $this->organization($request)->messages()->where('uuid', $uuid)->first();

        if ($message === null) {
            return response()->json(['message' => 'Сообщение не найдено'], 404);
        }

        return response()->json($this->payload($message));
    }

    private function byKey(Organization $organization, string $key): ?Message
    {
        return $organization->messages()->where('idempotency_key', $key)->first();
    }

    /**
     * Ключ уже использован: тот же запрос получает прежний ответ, другой запрос с этим ключом отклоняется.
     */
    private function replay(Message $message, int $templateId, string $to): JsonResponse
    {
        if ($message->template_id !== $templateId || $message->to_email !== $to) {
            return response()->json(['message' => 'Idempotency-Key уже использован для другого письма'], 422);
        }

        return response()->json($this->payload($message), $this->statusFor($message), ['Idempotent-Replayed' => 'true']);
    }

    private function statusFor(Message $message): int
    {
        return $message->status === Message::STATUS_BLOCKED ? 422 : 202;
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
            'attempts' => (int) $message->attempts,
            'error' => $message->error,
            'request_id' => $message->request_id,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }
}
