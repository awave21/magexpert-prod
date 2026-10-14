<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\NewsletterConsentWithdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * События Sender для сайта (контракт: docs/sender-api.openapi.yaml, раздел webhooks).
 * Подпись X-Sender-Signature-V2 = HMAC-SHA256("{X-Sender-Timestamp}.{тело}", секрет вебхука).
 * Защита от повторов: время не старше 5 минут, а событие с уже виденным id повторно не обрабатывается.
 */
class SenderWebhookController extends Controller
{
    /** События, после которых адрес больше не должен получать рассылки */
    private const WITHDRAWING_EVENTS = ['recipient.unsubscribed', 'message.complained'];

    /** Насколько давним может быть время в заголовке, секунд */
    private const TOLERANCE_SECONDS = 300;

    /** Сколько помним id событий, секунд (дольше, чем Sender повторяет доставку) */
    private const REMEMBER_SECONDS = 86400;

    public function __invoke(Request $request, NewsletterConsentWithdrawal $consent): JsonResponse
    {
        $secret = (string) config('services.sender.webhook_secret');
        $timestamp = (int) $request->header('X-Sender-Timestamp');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Sender-Signature-V2'))) {
            return response()->json(['message' => 'Подпись не совпадает'], 401);
        }

        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return response()->json(['message' => 'Запрос устарел'], 401);
        }

        // доставка повторяется, пока мы не ответили 2xx: уже обработанное событие просто подтверждаем
        $eventId = (string) $request->input('id', $request->header('X-Sender-Event-Id'));

        if ($eventId !== '' && ! Cache::add('sender.webhook.event.'.$eventId, true, self::REMEMBER_SECONDS)) {
            return response()->json(null, 204);
        }

        $event = (string) $request->input('event');

        if (in_array($event, self::WITHDRAWING_EVENTS, true)) {
            $email = $request->input('email') ?? $request->input('message.to');

            if (is_string($email) && $email !== '') {
                $consent->withdraw($email);
            }
        }

        return response()->json(null, 204);
    }
}
