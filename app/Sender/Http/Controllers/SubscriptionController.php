<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Http\Requests\SubscriptionRequest;
use App\Sender\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Приложение сообщает, согласен ли адрес получать рассылки: например, пользователь переключил галочку на сайте.
 */
class SubscriptionController extends Controller
{
    public function update(SubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        $organization = $request->attributes->get('sender_organization');
        $email = $request->string('email')->toString();

        $request->boolean('subscribed')
            ? $subscriptions->resubscribe($organization, $email)
            : $subscriptions->unsubscribe($organization, $email);

        return response()->json(['email' => mb_strtolower(trim($email)), 'subscribed' => $request->boolean('subscribed')]);
    }
}
