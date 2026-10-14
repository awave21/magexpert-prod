<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Http\Requests\SubscriptionRequest;
use App\Sender\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Канонический адрес: PUT /contacts/{email}/subscription. Адрес в пути, в теле только subscribed.
     */
    public function updateForContact(Request $request, SubscriptionService $subscriptions, string $email): JsonResponse
    {
        $email = mb_strtolower(trim(rawurldecode($email)));
        $validated = validator(
            ['email' => $email, 'subscribed' => $request->input('subscribed')],
            ['email' => ['required', 'email', 'max:255'], 'subscribed' => ['required', 'boolean']],
            ['email.email' => 'Адрес в пути некорректен', 'subscribed.required' => 'Укажите subscribed: true или false'],
        )->validate();

        $organization = $request->attributes->get('sender_organization');
        $subscribed = (bool) $validated['subscribed'];

        $subscribed
            ? $subscriptions->resubscribe($organization, $email)
            : $subscriptions->unsubscribe($organization, $email);

        return response()->json(['data' => ['email' => $email, 'subscribed' => $subscribed]]);
    }
}
