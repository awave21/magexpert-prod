<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Bitrix24Service
{
    protected string $webhookUrl;
    protected int $dealCategoryId;
    protected string $dealTypeId;
    protected int $eventEntityTypeId;
    protected int $assignedById;
    protected array $ufCodes;
    protected array $eventUfFields;
    protected array $httpConfig;
    protected bool $eventUnique;
    protected string $logChannel;
    protected array $dealStages;

    public function __construct()
    {
        $this->webhookUrl = rtrim(config('bitrix24.webhook_url', ''), '/');
        $this->dealCategoryId = (int) config('bitrix24.deal_category_id');
        $this->dealTypeId = (string) config('bitrix24.deal_type_id');
        $this->eventEntityTypeId = (int) config('bitrix24.event_entity_type_id');
        $this->assignedById = (int) config('bitrix24.assigned_by_id');
        $this->ufCodes = (array) config('bitrix24.uf_codes', []);
        $this->eventUfFields = (array) config('bitrix24.event_uf_fields', []);
        $this->httpConfig = (array) config('bitrix24.http', []);
        $this->eventUnique = (bool) config('bitrix24.event_unique', false);
        $this->logChannel = (string) config('bitrix24.log_channel', 'bitrix24');
        $this->dealStages = (array) config('bitrix24.deal_stages', []);
    }

    /**
     * Выполнить POST запрос к Bitrix24.
     */
    protected function post(string $method, array $payload): array
    {
        $url = $this->webhookUrl . '/' . ltrim($method, '/');

        // Логируем исходящий запрос в Bitrix24
        Log::channel($this->logChannel)->info('Bitrix24 → POST', [
            'method' => $method,
            'payload' => $payload,
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout($this->httpConfig['timeout'] ?? 15)
          ->connectTimeout($this->httpConfig['connect_timeout'] ?? 5)
          ->retry($this->httpConfig['retry_times'] ?? 3, $this->httpConfig['retry_sleep_ms'] ?? 250)
          ->post($url, $payload);

        // Логируем ответ Bitrix24 (статус и тело)
        $responseBody = null;
        try {
            $responseBody = $response->json();
        } catch (\Throwable $e) {
            $responseBody = $response->body();
        }

        Log::channel($this->logChannel)->info('Bitrix24 ← RESPONSE', [
            'method' => $method,
            'status' => $response->status(),
            'response' => $responseBody,
        ]);

        if (!$response->successful()) {
            Log::channel($this->logChannel)->error('Bitrix24: Ошибка HTTP', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Bitrix24: HTTP ' . $response->status());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            Log::channel($this->logChannel)->error('Bitrix24: Ошибка API', [
                'method' => $method,
                'error' => $data,
            ]);
            throw new \RuntimeException('Bitrix24 API error: ' . ($data['error_description'] ?? $data['error']));
        }

        return $data;
    }

    // ==================== Контакты ====================

    /** Найти контакт по email или телефону. */
    public function findContact(?string $email = null, ?string $phone = null): ?array
    {
        $filter = [];
        if ($email) { $filter['EMAIL'] = $email; }
        if ($phone) { $filter['PHONE'] = $phone; }

        $payload = [
            'filter' => $filter,
            'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'PHONE'],
        ];

        $data = $this->post('crm.contact.list.json', $payload);
        $items = $data['result'] ?? [];
        return !empty($items) ? $items[0] : null;
    }

    /** Создать контакт. */
    public function addContact(array $fields): int
    {
        $data = $this->post('crm.contact.add.json', ['fields' => $fields]);
        return (int) ($data['result'] ?? 0);
    }

    // ==================== Сделки ====================

    /** Найти сделки контакта в категории. */
    public function listDealsByContact(int $contactId, ?int $categoryId = null, ?string $typeId = null): array
    {
        $payload = [
            'filter' => [
                'CONTACT_ID' => $contactId,
                'CATEGORY_ID' => $categoryId ?? $this->dealCategoryId,
                'TYPE_ID' => $typeId ?? $this->dealTypeId,
            ],
            'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'ASSIGNED_BY_ID'],
        ];
        $data = $this->post('crm.deal.list.json', $payload);
        return $data['result'] ?? [];
    }

    /** Создать сделку. */
    public function addDeal(array $fields, array $params = []): int
    {
        $data = $this->post('crm.deal.add.json', [
            'fields' => $fields,
            'params' => array_merge(['REGISTER_SONET_EVENT' => 'N'], $params),
        ]);
        return (int) ($data['result'] ?? 0);
    }

    /** Обновить сделку. */
    public function updateDeal(int $dealId, array $fields): bool
    {
        $data = $this->post('crm.deal.update.json', [
            'id' => $dealId,
            'fields' => $fields,
        ]);
        return (bool) ($data['result'] ?? false);
    }

    /** Обновить стадию сделки, безопасно игнорируя пустые значения. */
    public function moveDealToStage(int $dealId, ?string $stageId): bool
    {
        if (!$stageId) {
            return false;
        }
        return $this->updateDeal($dealId, ['STAGE_ID' => $stageId]);
    }

    // ==================== Смарт-процесс «Мероприятие» ====================

    /** Создать элемент смарт-процесса. */
    public function addEventItem(array $fields): int
    {
        $data = $this->post('crm.item.add.json', [
            'entityTypeId' => $this->eventEntityTypeId,
            'fields' => $fields,
        ]);
        return (int) ($data['result']['item']['id'] ?? $data['result'] ?? 0);
    }

    /** Поиск элементов смарт-процесса по фильтру. */
    public function listEventItems(array $filter, array $select = ['id', 'title', 'assignedById']): array
    {
        $data = $this->post('crm.item.list.json', [
            'entityTypeId' => $this->eventEntityTypeId,
            'filter' => $filter,
            'select' => $select,
        ]);
        return $data['result']['items'] ?? ($data['result'] ?? []);
    }

    /** Обновить элемент смарт-процесса. */
    public function updateEventItem(int $itemId, array $fields): bool
    {
        $data = $this->post('crm.item.update.json', [
            'entityTypeId' => $this->eventEntityTypeId,
            'id' => $itemId,
            'fields' => $fields,
        ]);
        return (bool) ($data['result'] ?? false);
    }
}


