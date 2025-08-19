<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\Payment;
use App\Services\PayKeeperService;
use App\Services\SendsayService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    protected PayKeeperService $payKeeper;
    protected SendsayService $sendsayService;

    public function __construct(PayKeeperService $payKeeper, SendsayService $sendsayService)
    {
        $this->payKeeper = $payKeeper;
        $this->sendsayService = $sendsayService;
    }
    /**
     * Создать платежную ссылку для регистрации на мероприятие
     *
     * @param Event $event
     * @param User $user
     * @param array $additionalData
     * @return array
     */
    public function createPaymentLink(Event $event, User $user, array $additionalData = []): array
    {
        try {
            // Проверяем, что мероприятие платное
            if (!$event->isPaid()) {
                throw new \Exception('Мероприятие не является платным');
            }

            // Проверяем, что цена указана
            if (!$event->price || $event->price <= 0) {
                throw new \Exception('Цена мероприятия не указана');
            }

            // Создаем запись о платеже
            $payment = $this->createPaymentRecord($event, $user, $additionalData);

            // Формируем платежную ссылку (пока заглушка)
            $paymentUrl = $this->generatePaymentUrl($payment);

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'payment_url' => $paymentUrl,
                'amount' => $event->price,
                'currency' => 'RUB',
                'description' => $event->is_archived 
                    ? "Оплата доступа к записи мероприятия: {$event->title}"
                    : "Оплата участия в мероприятии: {$event->title}",
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка создания платежной ссылки: ' . $e->getMessage(), [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Создать запись о платеже в базе данных
     *
     * @param Event $event
     * @param User $user
     * @param array $additionalData
     * @return Payment
     */
    protected function createPaymentRecord(Event $event, User $user, array $additionalData = []): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'amount' => $event->price,
            'currency' => 'RUB',
            'status' => 'pending',
            'payment_system' => $this->getPaymentSystem(),
            'external_id' => null, // Будет заполнено после создания в платежной системе
            'description' => $event->is_archived 
                ? "Оплата доступа к записи мероприятия: {$event->title}"
                : "Оплата участия в мероприятии: {$event->title}",
            'metadata' => array_merge([
                'event_title' => $event->title,
                'event_slug' => $event->slug,
                'user_email' => $user->email,
                'user_name' => trim($user->first_name . ' ' . $user->last_name),
            ], $additionalData),
        ]);
    }

    /**
     * Сгенерировать платежную ссылку через PayKeeper
     *
     * @param Payment $payment
     * @return string
     */
    protected function generatePaymentUrl(Payment $payment): string
    {
        try {
            // Проверяем, включен ли PayKeeper
            if (!config('paykeeper.enabled', false)) {
                // Возвращаем тестовую ссылку если PayKeeper отключен
                return route('payment.process', [
                    'payment' => $payment->id,
                    'token' => $payment->generateSecureToken()
                ]);
            }

            // Подготавливаем данные для PayKeeper
            $customerData = [
                'name' => $payment->metadata['user_name'] ?? '',
                'email' => $payment->metadata['user_email'] ?? '',
                'phone' => $payment->metadata['user_data']['phone'] ?? $payment->user->phone ?? ''
            ];

            // Генерируем номер заказа в формате ORD + ID с ведущими нулями
            $orderNumber = sprintf('ORD%06d', $payment->id);
            
            // Сохраняем order_id в платеже
            $payment->update(['order_id' => $orderNumber]);
            
            $additionalData = [
                'order_id' => $orderNumber,
            ];

            $paymentData = $this->payKeeper->preparePaymentData(
                $payment->amount,
                $payment->description,
                $customerData,
                $additionalData
            );

            // Создаем ссылку через PayKeeper
            $result = $this->payKeeper->createPaymentLink($paymentData);

            if ($result['success']) {
                // Сохраняем external_id для отслеживания
                $payment->update([
                    'external_id' => $result['invoice_id'],
                    'payment_system' => 'paykeeper'
                ]);

                return $result['payment_url'];
            } else {
                throw new \Exception($result['error']);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка создания ссылки PayKeeper: ' . $e->getMessage(), [
                'payment_id' => $payment->id
            ]);

            // В случае ошибки возвращаем тестовую ссылку
            return route('payment.process', [
                'payment' => $payment->id,
                'token' => $payment->generateSecureToken()
            ]);
        }
    }

    /**
     * Получить название платежной системы
     *
     * @return string
     */
    protected function getPaymentSystem(): string
    {
        return config('paykeeper.enabled', false) ? 'paykeeper' : 'test';
    }

    /**
     * Обработать callback от платежной системы
     *
     * @param string $paymentId
     * @param array $callbackData
     * @return array
     */
    public function processCallback(string $paymentId, array $callbackData): array
    {
        try {
            $payment = Payment::findOrFail($paymentId);

            // TODO: Проверить подпись callback'а от платежной системы
            
            // Обновляем статус платежа
            $this->updatePaymentStatus($payment, $callbackData);

            // Если платеж успешен, предоставляем доступ к мероприятию
            if ($payment->status === 'completed') {
                $this->grantEventAccess($payment);
            }

            return [
                'success' => true,
                'status' => $payment->status
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка обработки callback платежа: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'callback_data' => $callbackData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Обновить статус платежа
     *
     * @param Payment $payment
     * @param array $callbackData
     * @return void
     */
    protected function updatePaymentStatus(Payment $payment, array $callbackData): void
    {
        // TODO: Реализовать логику обновления статуса на основе данных от платежной системы
        
        // Пример для заглушки:
        $status = $callbackData['status'] ?? 'pending';
        
        $payment->update([
            'status' => $status,
            'external_id' => $callbackData['external_id'] ?? null,
            'paid_at' => $status === 'completed' ? now() : null,
            'callback_data' => $callbackData,
        ]);
    }

    /**
     * Предоставить доступ к мероприятию после успешной оплаты
     *
     * @param Payment $payment
     * @return void
     */
    public function grantEventAccess(Payment $payment): void
    {
        $event = $payment->event;
        $user = $payment->user;

        // Проверяем, нет ли уже доступа
        if (!$event->hasUserAccess($user)) {
            // Предоставляем доступ через связь many-to-many
            $event->users()->attach($user->id, [
                'access_type' => 'paid',
                'payment_amount' => $payment->amount,
                'payment_id' => $payment->id,
                'payment_status' => 'completed',
                'access_granted_at' => now(),
                'is_active' => true,
            ]);

            Log::info('Предоставлен доступ к мероприятию после оплаты', [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'amount' => $payment->amount
            ]);

            // Загружаем спикеров для отправки в письме
            $event->load([
                'speakers' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('pivot_sort_order', 'asc')
                          ->orderBy('last_name', 'asc');
                }
            ]);

            // Отправляем email уведомление о регистрации
            try {
                // Получаем пароль из дополнительных данных платежа если есть
                $generatedPassword = '';
                $isNewUser = false;
                
                if (isset($payment->metadata['generated_password'])) {
                    $generatedPassword = $payment->metadata['generated_password'];
                    $isNewUser = $payment->metadata['is_new_user'] ?? false;
                }

                $this->sendsayService->sendEventRegistrationEmail($event, $user, $generatedPassword, $isNewUser);
            } catch (\Exception $e) {
                Log::warning('Не удалось отправить email о регистрации на платное мероприятие', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage()
                ]);
                // Не прерываем выполнение, так как пользователь уже зарегистрирован
            }
        }
    }

    /**
     * Проверить статус платежа
     *
     * @param string $paymentId
     * @return array
     */
    public function checkPaymentStatus(string $paymentId): array
    {
        try {
            $payment = Payment::findOrFail($paymentId);

            // Если используется PayKeeper и есть external_id, проверяем статус в системе
            if ($payment->payment_system === 'paykeeper' && $payment->external_id) {
                $result = $this->payKeeper->getInvoiceStatus($payment->external_id);
                
                if ($result['success']) {
                    // Обновляем локальный статус если он изменился
                    $normalizedStatus = $this->normalizePayKeeperStatus($result['status']);
                    if ($payment->status !== $normalizedStatus) {
                        $payment->update([
                            'status' => $normalizedStatus,
                            'paid_at' => $normalizedStatus === 'completed' ? now() : $payment->paid_at
                        ]);
                    }
                }
            }
            
            return [
                'success' => true,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Нормализовать статус от PayKeeper
     *
     * @param string $payKeeperStatus
     * @return string
     */
    public function normalizePayKeeperStatus(string $payKeeperStatus): string
    {
        return match ($payKeeperStatus) {
            'created', 'sent' => 'pending',
            'paid' => 'completed',
            'expired' => 'expired',
            default => 'pending'
        };
    }

    /**
     * Отменить платеж
     *
     * @param string $paymentId
     * @return array
     */
    public function cancelPayment(string $paymentId): array
    {
        try {
            $payment = Payment::findOrFail($paymentId);

            if ($payment->status !== 'pending') {
                throw new \Exception('Платеж нельзя отменить в текущем статусе');
            }

            $payment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Платеж отменен'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}