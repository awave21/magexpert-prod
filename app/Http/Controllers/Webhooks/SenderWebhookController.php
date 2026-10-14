<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\NewsletterConsentWithdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * События Sender для сайта (контракт: docs/sender-api.openapi.yaml, раздел webhooks).
 * Тело подписано HMAC-SHA256 секретом вебхука в заголовке X-Sender-Signature.
 */
class SenderWebhookController extends Controller
{
    /** События, после которых адрес больше не должен получать рассылки */
    private const WITHDRAWING_EVENTS = ['recipient.unsubscribed', 'message.complained'];

    public function __invoke(Request $request, NewsletterConsentWithdrawal $consent): JsonResponse
    {
        $secret = (string) config('services.sender.webhook_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Sender-Signature'))) {
            return response()->json(['message' => 'Подпись не совпадает'], 401);
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
