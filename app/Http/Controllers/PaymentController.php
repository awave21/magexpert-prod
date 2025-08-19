<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Event;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Создать платежную ссылку для мероприятия
     *
     * @param Request $request
     * @param Event $event
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPayment(Request $request, Event $event)
    {
        try {
            // Валидация данных
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'return_url' => 'nullable|url',
                'metadata' => 'nullable|array',
            ]);

            // Получаем пользователя
            $user = \App\Models\User::findOrFail($validated['user_id']);

            // Создаем платежную ссылку
            $result = $this->paymentService->createPaymentLink($event, $user, [
                'return_url' => $validated['return_url'] ?? route('events.show', $event->slug),
                'metadata' => $validated['metadata'] ?? [],
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error']
                ], 400);
            }

            return response()->json([
                'success' => true,
                'payment_id' => $result['payment_id'],
                'payment_url' => $result['payment_url'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
                'description' => $result['description'],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации данных',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Ошибка создания платежа: ' . $e->getMessage(), [
                'event_id' => $event->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Произошла ошибка при создании платежа'
            ], 500);
        }
    }

    /**
     * Обработать платеж (страница оплаты)
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function processPayment(Request $request, Payment $payment)
    {
        // Проверяем токен безопасности
        $token = $request->get('token');
        if (!$token || !$payment->verifySecureToken($token)) {
            abort(403, 'Недействительная ссылка для оплаты');
        }

        // Проверяем статус платежа
        if (!$payment->isPending()) {
            return redirect()->route('events.show', $payment->event->slug)
                ->with('info', 'Платеж уже обработан');
        }

        // TODO: Интеграция с реальной платежной системой
        // Пока показываем тестовую страницу оплаты
        return Inertia::render('Payment/Process', [
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'description' => $payment->description,
                'status' => $payment->status,
                'event' => [
                    'id' => $payment->event->id,
                    'title' => $payment->event->title,
                    'slug' => $payment->event->slug,
                ],
                'user' => [
                    'id' => $payment->user->id,
                    'name' => trim($payment->user->first_name . ' ' . $payment->user->last_name),
                    'email' => $payment->user->email,
                ]
            ]
        ]);
    }

    /**
     * Имитация успешной оплаты (только для тестирования)
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Illuminate\Http\RedirectResponse
     */
    public function simulateSuccess(Request $request, Payment $payment)
    {
        // Проверяем токен безопасности
        $token = $request->get('token');
        if (!$token || !$payment->verifySecureToken($token)) {
            abort(403, 'Недействительная ссылка');
        }

        // Только для тестовой системы
        if ($payment->payment_system !== 'test') {
            abort(404);
        }

        // Имитируем callback от платежной системы
        $result = $this->paymentService->processCallback($payment->id, [
            'status' => 'completed',
            'external_id' => 'test_' . time(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);

        if ($result['success']) {
            return redirect()->route('events.show', $payment->event->slug)
                ->with('success', 'Оплата прошла успешно! Вы зарегистрированы на мероприятие.');
        }

        return redirect()->route('events.show', $payment->event->slug)
            ->with('error', 'Произошла ошибка при обработке платежа');
    }

    /**
     * Обработать callback от платежной системы
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleCallback(Request $request)
    {
        try {
            // TODO: Валидация подписи callback'а от платежной системы
            
            $paymentId = $request->input('payment_id');
            if (!$paymentId) {
                return response()->json(['error' => 'payment_id is required'], 400);
            }

            $result = $this->paymentService->processCallback($paymentId, $request->all());

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Ошибка обработки callback: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Проверить статус платежа
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkStatus(Request $request, Payment $payment)
    {
        try {
            // Проверяем токен безопасности
            $token = $request->get('token');
            if (!$token || !$payment->verifySecureToken($token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Недействительный токен'
                ], 403);
            }

            $result = $this->paymentService->checkPaymentStatus($payment->id);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка проверки статуса платежа'
            ], 500);
        }
    }

    /**
     * Отменить платеж
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelPayment(Request $request, Payment $payment)
    {
        try {
            // Проверяем токен безопасности
            $token = $request->get('token');
            if (!$token || !$payment->verifySecureToken($token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Недействительный токен'
                ], 403);
            }

            $result = $this->paymentService->cancelPayment($payment->id);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка отмены платежа'
            ], 500);
        }
    }

    /**
     * Страница успешной оплаты
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function success(Request $request, Payment $payment)
    {
        // Проверяем токен безопасности
        $token = $request->get('token');
        if (!$token || !$payment->verifySecureToken($token)) {
            abort(403, 'Недействительная ссылка');
        }

        if (!$payment->isCompleted()) {
            return redirect()->route('events.show', $payment->event->slug)
                ->with('info', 'Платеж еще не завершен');
        }

        return Inertia::render('Payment/Success', [
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at,
                'updated_at' => $payment->updated_at,
                'user_email' => $payment->user->email,
                'user_name' => trim($payment->user->first_name . ' ' . $payment->user->last_name),
            ],
            'event' => $payment->event ? [
                'id' => $payment->event->id,
                'title' => $payment->event->title,
                'slug' => $payment->event->slug,
                'date' => $payment->event->date,
                'description' => $payment->event->description,
            ] : null
        ]);
    }

    /**
     * Страница ошибки оплаты
     *
     * @param Request $request
     * @param Payment $payment
     * @return \Inertia\Response
     */
    public function failed(Request $request, Payment $payment)
    {
        // Проверяем токен безопасности
        $token = $request->get('token');
        if (!$token || !$payment->verifySecureToken($token)) {
            abort(403, 'Недействительная ссылка');
        }

        return Inertia::render('Payment/Failed', [
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'updated_at' => $payment->updated_at,
                'user_email' => $payment->user->email,
                'user_name' => trim($payment->user->first_name . ' ' . $payment->user->last_name),
            ],
            'event' => $payment->event ? [
                'id' => $payment->event->id,
                'title' => $payment->event->title,
                'slug' => $payment->event->slug,
                'date' => $payment->event->date,
                'description' => $payment->event->description,
            ] : null,
            'error_message' => $request->get('error_message', 'Произошла ошибка при обработке платежа')
        ]);
    }

    /**
     * Статичная страница успешной оплаты (без параметра платежа)
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function staticSuccess(Request $request)
    {
        return Inertia::render('Payment/StaticSuccess', [
            'message' => 'Оплата прошла успешно!',
            'description' => 'Спасибо за вашу оплату. Подтверждение будет отправлено на ваш email.',
        ]);
    }

    /**
     * Статичная страница ошибки оплаты (без параметра платежа)
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function staticFailed(Request $request)
    {
        return Inertia::render('Payment/StaticFailed', [
            'message' => 'Оплата не удалась',
            'description' => 'Произошла ошибка при обработке платежа. Попробуйте еще раз или обратитесь в службу поддержки.',
            'error_message' => $request->get('error_message', 'Ошибка обработки платежа')
        ]);
    }
}