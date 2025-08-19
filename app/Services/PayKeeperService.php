<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class PayKeeperService
{
    protected string $serverUrl;
    protected string $username;
    protected string $password;
    protected array $endpoints;
    protected bool $testMode;

    public function __construct()
    {
        $this->serverUrl = config('paykeeper.server_url');
        $this->username = config('paykeeper.username');
        $this->password = config('paykeeper.password');
        $this->endpoints = config('paykeeper.endpoints');
        $this->testMode = config('paykeeper.test_mode', true);
    }

    /**
     * Получить экземпляр логгера для PayKeeper webhook'ов
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected function getWebhookLogger()
    {
        return Log::channel('paykeeper_webhooks');
    }

    /**
     * Получить токен безопасности для API запросов
     *
     * @return string|null
     * @throws \Exception
     */
    protected function getSecurityToken(): ?string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            ])->get($this->serverUrl . $this->endpoints['token']);

            if (!$response->successful()) {
                throw new \Exception('Ошибка получения токена безопасности: ' . $response->body());
            }

            $data = $response->json();
            
            if (!isset($data['token'])) {
                throw new \Exception('Токен не найден в ответе от PayKeeper');
            }

            return $data['token'];

        } catch (\Exception $e) {
            Log::error('PayKeeper: Ошибка получения токена', [
                'error' => $e->getMessage(),
                'server_url' => $this->serverUrl
            ]);
            throw $e;
        }
    }

    /**
     * Создать ссылку на оплату
     *
     * @param array $paymentData
     * @return array
     * @throws \Exception
     */
    public function createPaymentLink(array $paymentData): array
    {
        try {
            // Получаем токен безопасности
            $token = $this->getSecurityToken();

            // Подготавливаем данные для создания счета
            $requestData = array_merge($paymentData, ['token' => $token]);

            // Создаем счет
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            ])->asForm()->post($this->serverUrl . $this->endpoints['create_invoice'], $requestData);

            if (!$response->successful()) {
                throw new \Exception('Ошибка создания счета в PayKeeper: ' . $response->body());
            }

            $responseData = $response->json();

            if (!isset($responseData['invoice_id'])) {
                throw new \Exception('ID счета не найден в ответе от PayKeeper');
            }

            $invoiceId = $responseData['invoice_id'];
            $paymentUrl = $this->serverUrl . '/bill/' . $invoiceId . '/';

            $this->getWebhookLogger()->info('PayKeeper: Создана ссылка на оплату', [
                'timestamp' => now()->toDateTimeString(),
                'invoice_id' => $invoiceId,
                'amount' => $paymentData['pay_amount'] ?? null,
                'payment_url' => $paymentUrl,
                'customer_data' => [
                    'name' => $paymentData['clientid'] ?? null,
                    'email' => $paymentData['client_email'] ?? null,
                    'order_id' => $paymentData['orderid'] ?? null
                ]
            ]);

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'payment_url' => $paymentUrl,
                'server_response' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('PayKeeper: Ошибка создания ссылки на оплату', [
                'error' => $e->getMessage(),
                'payment_data' => $paymentData
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Проверить статус счета
     *
     * @param string $invoiceId
     * @return array
     */
    public function getInvoiceStatus(string $invoiceId): array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            ])->get($this->serverUrl . $this->endpoints['invoice_info'], [
                'id' => $invoiceId
            ]);

            if (!$response->successful()) {
                throw new \Exception('Ошибка получения статуса счета: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['status'])) {
                throw new \Exception('Статус не найден в ответе от PayKeeper');
            }

            return [
                'success' => true,
                'status' => $data['status'],
                'data' => $data
            ];

        } catch (\Exception $e) {
            Log::error('PayKeeper: Ошибка получения статуса счета', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Подготовить данные для создания платежа
     *
     * @param float $amount
     * @param string $description
     * @param array $customerData
     * @param array $additionalData
     * @return array
     */
    public function preparePaymentData(
        float $amount,
        string $description,
        array $customerData = [],
        array $additionalData = []
    ): array {
        $paymentData = [
            'pay_amount' => $amount,
            'service_name' => $description,
        ];

        // Добавляем данные клиента
        if (isset($customerData['name'])) {
            $paymentData['clientid'] = $customerData['name'];
        }

        if (isset($customerData['email'])) {
            $paymentData['client_email'] = $customerData['email'];
        }

        if (isset($customerData['phone'])) {
            $paymentData['client_phone'] = $customerData['phone'];
        }

        // Добавляем дополнительные данные
        if (isset($additionalData['order_id'])) {
            $paymentData['orderid'] = $additionalData['order_id'];
        }

        return array_merge($paymentData, $additionalData);
    }

    /**
     * Проверить валидность webhook уведомления
     *
     * @param array $data
     * @param string|null $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $data, ?string $signature = null): bool
    {
        // TODO: Реализовать проверку подписи webhook если PayKeeper поддерживает
        // Пока возвращаем true для базовой функциональности
        return true;
    }

    /**
     * Обработать POST оповещение от PayKeeper согласно документации
     *
     * @param array $data
     * @return array
     */
    public function processWebhookData(array $data): array
    {
        try {
            // Валидация согласно документации PayKeeper
            if (!isset($data['id']) || !isset($data['sum']) || !isset($data['key'])) {
                throw new \Exception('Отсутствуют обязательные поля (id, sum, key)');
            }

            // Извлекаем основные данные
            $invoiceId = $data['id'];
            $amount = (float) $data['sum'];
            
            // Дополнительные данные
            $clientid = $data['clientid'] ?? '';
            $orderid = $data['orderid'] ?? '';
            $serviceName = $data['service_name'] ?? '';
            $clientEmail = $data['client_email'] ?? '';
            $clientPhone = $data['client_phone'] ?? '';
            
            // Данные платежной системы
            $psId = $data['ps_id'] ?? null;
            $batchDate = $data['batch_date'] ?? null;
            $fopReceiptKey = $data['fop_receipt_key'] ?? null;
            
            // Данные карты
            $bankId = $data['bank_id'] ?? null;
            $cardNumber = $data['card_number'] ?? null;
            $cardHolder = $data['card_holder'] ?? null;
            $cardExpiry = $data['card_expiry'] ?? null;

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'status' => 'completed', // POST оповещение приходит только при успешном платеже
                'amount' => $amount,
                'client_data' => [
                    'clientid' => $clientid,
                    'orderid' => $orderid,
                    'service_name' => $serviceName,
                    'client_email' => $clientEmail,
                    'client_phone' => $clientPhone,
                ],
                'payment_data' => [
                    'ps_id' => $psId,
                    'batch_date' => $batchDate,
                    'fop_receipt_key' => $fopReceiptKey,
                    'bank_id' => $bankId,
                    'card_number' => $cardNumber,
                    'card_holder' => $cardHolder,
                    'card_expiry' => $cardExpiry,
                ],
                'original_data' => $data
            ];

        } catch (\Exception $e) {
            $this->getWebhookLogger()->error('PayKeeper: Ошибка обработки POST оповещения', [
                'timestamp' => now()->toDateTimeString(),
                'error' => $e->getMessage(),
                'webhook_data' => $data
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Сгенерировать ответ для PayKeeper согласно документации
     *
     * @param string $invoiceId
     * @return string
     */
    public function generateWebhookResponse(string $invoiceId): string
    {
        $secret = config('paykeeper.webhook_secret');
        
        if (empty($secret)) {
            // Если секрет не настроен, возвращаем простой OK
            return 'OK';
        }
        
        // Согласно документации: OK + md5(id + secret_seed)
        $hash = md5($invoiceId . $secret);
        
        return "OK {$hash}";
    }

    /**
     * Нормализовать статус платежа от PayKeeper
     *
     * @param string $payKeeperStatus
     * @return string
     */
    protected function normalizePaymentStatus(string $payKeeperStatus): string
    {
        return match ($payKeeperStatus) {
            'created', 'sent' => 'pending',
            'paid' => 'completed',
            'expired' => 'expired',
            default => 'unknown'
        };
    }
}