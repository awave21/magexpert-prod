<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\PayKeeperService;
use App\Services\Bitrix24RegistrationService;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PayKeeperWebhookController extends Controller
{
    protected PaymentService $paymentService;
    protected PayKeeperService $payKeeper;
    protected Bitrix24RegistrationService $bitrix24Registration;

    public function __construct(PaymentService $paymentService, PayKeeperService $payKeeper, Bitrix24RegistrationService $bitrix24Registration)
    {
        $this->paymentService = $paymentService;
        $this->payKeeper = $payKeeper;
        $this->bitrix24Registration = $bitrix24Registration;
    }

    /**
     * Получить экземпляр логгера для webhook'ов PayKeeper
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function getWebhookLogger()
    {
        return Log::channel('paykeeper_webhooks');
    }

    /**
     * Обработать POST оповещение от PayKeeper
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        try {
            $data = $request->all();
            
            $this->getWebhookLogger()->info('PayKeeper Webhook получен', [
                'timestamp' => now()->toDateTimeString(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'data' => $data,
                'headers' => $request->headers->all()
            ]);

            // Валидация данных webhook
            $processedData = $this->payKeeper->processWebhookData($data);
            
            if (!$processedData['success']) {
                $this->getWebhookLogger()->error('PayKeeper POST: Ошибка обработки данных', [
                    'timestamp' => now()->toDateTimeString(),
                    'error' => $processedData['error'],
                    'data' => $data
                ]);
                
                return response('Error! Invalid data', 400);
            }

            // Ищем платеж по orderid (наш внутренний номер заказа)
            $orderid = $processedData['client_data']['orderid'] ?? null;
            
            if (!$orderid) {
                $this->getWebhookLogger()->warning('PayKeeper POST: отсутствует orderid в данных webhook', [
                    'timestamp' => now()->toDateTimeString(),
                    'data' => $processedData
                ]);
                return response('Error! Missing orderid', 400);
            }
            
            // Ищем платеж напрямую по order_id
            $payment = Payment::where('order_id', $orderid)
                             ->where('payment_system', 'paykeeper')
                             ->first();
            
            // Для обратной совместимости также проверяем старые форматы
            if (!$payment) {
                $paymentId = null;
                
                if (preg_match('/^payment_(\d+)$/', $orderid, $matches)) {
                    // Старый формат: payment_{id}
                    $paymentId = (int) $matches[1];
                } elseif (preg_match('/^ORD(\d+)$/', $orderid, $matches)) {
                    // Новый формат: ORD{id} (если order_id поле пустое)
                    $paymentId = (int) $matches[1];
                }
                
                if ($paymentId) {
                    $payment = Payment::where('id', $paymentId)
                                     ->where('payment_system', 'paykeeper')
                                     ->first();
                }
            }

            if (!$payment) {
                $this->getWebhookLogger()->warning('PayKeeper POST: Платеж не найден', [
                    'timestamp' => now()->toDateTimeString(),
                    'invoice_id' => $processedData['invoice_id'],
                    'orderid' => $orderid,
                    'searched_by' => 'order_id'
                ]);
                
                return response('Error! Payment not found', 404);
            }

            // Проверяем, не обработан ли уже этот платеж (защита от дублирования)
            if ($payment->status === 'completed') {
                $this->getWebhookLogger()->info('PayKeeper POST: Платеж уже обработан', [
                    'timestamp' => now()->toDateTimeString(),
                    'payment_id' => $payment->id,
                    'invoice_id' => $processedData['invoice_id']
                ]);
                
                // Возвращаем успешный ответ даже для уже обработанного платежа
                $response = $this->payKeeper->generateWebhookResponse($processedData['invoice_id']);
                return response($response, 200);
            }

            // Обновляем статус платежа
            $this->updatePaymentFromWebhook($payment, $processedData);

            // Предоставляем доступ к мероприятию (статус уже установлен в 'completed')
            $this->paymentService->grantEventAccess($payment);

            // Обновляем статус элемента смарт‑процесса на «Оплачено» (если поле настроено)
            try {
                $eventUf = config('bitrix24.event_uf_fields');
                if (!empty($eventUf['status'])) {
                    $b24 = app(\App\Services\Bitrix24Service::class);
                    $contact = $b24->findContact($payment->user->email, $payment->user->phone);
                    if ($contact) {
                        $contactId = (int) $contact['ID'];
                        $deals = $b24->listDealsByContact($contactId);
                        $dealId = !empty($deals) ? (int) $deals[0]['ID'] : null;

                        $filter = ['contactId' => $contactId];
                        if ($dealId) {
                            $filter['parentId2'] = $dealId;
                        }

                        $items = $b24->listEventItems($filter, ['id']);
                        if (!empty($items)) {
                            $itemId = (int) ($items[0]['id'] ?? $items[0]['ID'] ?? 0);
                            if ($itemId > 0) {
                                $b24->updateEventItem($itemId, [
                                    $eventUf['status'] => 'Оплачено',
                                ]);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->getWebhookLogger()->warning('Bitrix24: ошибка обновления статуса смарт‑процесса на Оплачено', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->getWebhookLogger()->info('PayKeeper POST оповещение успешно обработано', [
                'timestamp' => now()->toDateTimeString(),
                'payment_id' => $payment->id,
                'invoice_id' => $processedData['invoice_id'],
                'old_status' => $payment->getOriginal('status'),
                'new_status' => $payment->status,
                'amount' => $payment->amount,
                'event_id' => $payment->event_id,
                'user_id' => $payment->user_id,
                'client_data' => $processedData['client_data'],
                'payment_system_id' => $processedData['payment_data']['ps_id']
            ]);

            // Возвращаем ответ согласно документации PayKeeper
            $response = $this->payKeeper->generateWebhookResponse($processedData['invoice_id']);
            return response($response, 200);

        } catch (\Exception $e) {
            $this->getWebhookLogger()->error('PayKeeper POST: Критическая ошибка обработки', [
                'timestamp' => now()->toDateTimeString(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response('Error! Internal server error', 500);
        }
    }

    /**
     * Обновить платеж на основе данных webhook
     *
     * @param Payment $payment
     * @param array $webhookData
     * @return void
     */
    protected function updatePaymentFromWebhook(Payment $payment, array $webhookData): void
    {
        $updateData = [
            'status' => 'completed', // POST оповещения приходят только при успешной оплате
            'paid_at' => now(),
            'callback_data' => $webhookData['original_data'],
            'external_id' => $webhookData['invoice_id'], // Обновляем на актуальный PayKeeper ID
        ];

        // Обновляем сумму если она отличается (на случай скидок или комиссий)
        if (isset($webhookData['amount']) && $webhookData['amount'] > 0) {
            $actualAmount = (float) $webhookData['amount'];
            if (abs($payment->amount - $actualAmount) > 0.01) {
                $updateData['amount'] = $actualAmount;
                
                $this->getWebhookLogger()->warning('PayKeeper: Сумма платежа изменена', [
                    'timestamp' => now()->toDateTimeString(),
                    'payment_id' => $payment->id,
                    'original_amount' => $payment->amount,
                    'actual_amount' => $actualAmount
                ]);
            }
        }

        $payment->update($updateData);

        $this->getWebhookLogger()->info('PayKeeper: Платеж отмечен как оплаченный', [
            'timestamp' => now()->toDateTimeString(),
            'payment_id' => $payment->id,
            'old_status' => $payment->getOriginal('status'),
            'new_status' => 'completed',
            'amount' => $payment->amount,
            'invoice_id' => $payment->external_id,
            'payment_system_id' => $webhookData['payment_data']['ps_id'] ?? null
        ]);
    }
}