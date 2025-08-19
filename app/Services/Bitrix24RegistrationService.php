<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class Bitrix24RegistrationService
{
    protected Bitrix24Service $b24;

    public function __construct(Bitrix24Service $b24)
    {
        $this->b24 = $b24;
    }

    /**
     * Оркестрация: создать/найти контакт → создать/обновить сделку → создать элемент «Мероприятие».
     * Возвращает: ['success' => bool, 'contactId' => int, 'dealId' => int|null, 'itemId' => int|null]
     */
    public function syncRegistration(Event $event, User $user, array $payload = []): array
    {
        try {
            // 1) Контакт
            $contact = $this->b24->findContact($user->email, $user->phone);
            if (!$contact) {
                $contactFields = $this->buildContactFields($user, $payload);
                $contactId = $this->b24->addContact($contactFields);
            } else {
                $contactId = (int) $contact['ID'];
            }

            // 2) Сделка (опционально: если требуется по бизнес-логике)
            $dealId = null;
            $deals = $this->b24->listDealsByContact($contactId);
            if (!empty($deals)) {
                $dealId = (int) $deals[0]['ID'];
                // При необходимости — обновление сделки (минимально)
                if (!empty($payload['deal_update_fields'])) {
                    $this->b24->updateDeal($dealId, $payload['deal_update_fields']);
                }
            } else {
                $dealFields = $this->buildDealFields($event, $contactId, $payload);
                $dealId = $this->b24->addDeal($dealFields, $payload['deal_params'] ?? []);
            }

            // 3) Смарт-процесс «Мероприятие»
            $itemId = null;
            $eventItemFields = $this->buildEventItemFields($event, $contactId, $dealId, $payload);

            $eventUnique = (bool) config('bitrix24.event_unique', false);
            if ($eventUnique) {
                $filter = [
                    'contactId' => $contactId,
                ];
                if ($dealId) {
                    $filter['parentId2'] = $dealId;
                }
                if (!empty($eventItemFields['title'])) {
                    $filter['title'] = $eventItemFields['title'];
                }

                $existingItems = $this->b24->listEventItems($filter);
                if (!empty($existingItems)) {
                    // Уже существует — пропускаем создание
                    $itemId = (int) ($existingItems[0]['id'] ?? $existingItems[0]['ID'] ?? 0);
                    // Если нашли — обновим статус, если требуется
                    $statusKey = $this->normalizeUfKey(config('bitrix24.event_uf_fields.status'));
                    if ($statusKey && !empty($eventItemFields[$statusKey])) {
                        $this->b24->updateEventItem($itemId, [
                            $statusKey => $eventItemFields[$statusKey],
                        ]);
                    }
                } else {
                    $itemId = $this->b24->addEventItem($eventItemFields);
                }
            } else {
                $itemId = $this->b24->addEventItem($eventItemFields);
            }

            return [
                'success' => true,
                'contactId' => $contactId,
                'dealId' => $dealId,
                'itemId' => $itemId,
            ];
        } catch (\Throwable $e) {
            Log::channel(config('bitrix24.log_channel', 'bitrix24'))
                ->error('Ошибка синхронизации регистрации с Bitrix24', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ==================== Маппинг полей ====================

    /** Подготовка полей контакта. */
    protected function buildContactFields(User $user, array $payload = []): array
    {
        $uf = config('bitrix24.uf_codes');

        $fields = [
            'NAME' => $user->first_name ?: ($payload['first_name'] ?? ''),
            'SECOND_NAME' => $user->middle_name ?: ($payload['middle_name'] ?? ''),
            'LAST_NAME' => $user->last_name ?: ($payload['last_name'] ?? ''),
            'SOURCE_ID' => config('bitrix24.source_id', 'WEB'),
            'SOURCE_DESCRIPTION' => config('bitrix24.source_description', 'Регистрация на мероприятие'),
            'COMMENTS' => $payload['contact_comments'] ?? 'Заявка с лендинга',
            'ASSIGNED_BY_ID' => (int) config('bitrix24.assigned_by_id'),
            'UTM_SOURCE' => $payload['utm_source'] ?? null,
            'UTM_MEDIUM' => $payload['utm_medium'] ?? null,
            'UTM_CAMPAIGN' => $payload['utm_campaign'] ?? null,
            'UTM_CONTENT' => $payload['utm_content'] ?? null,
            'UTM_TERM' => $payload['utm_term'] ?? null,
            $uf['city'] ?? 'UF_CRM_ITS_DICT_CITY' => $user->city ?: ($payload['city'] ?? null),
            'PHONE' => [],
            'EMAIL' => [],
        ];

        if (!empty($user->phone) || !empty($payload['phone'])) {
            $fields['PHONE'][] = [
                'VALUE' => $user->phone ?: $payload['phone'],
                'VALUE_TYPE' => 'MOBILE',
            ];
        }
        if (!empty($user->email)) {
            $fields['EMAIL'][] = [
                'VALUE' => $user->email,
                'VALUE_TYPE' => 'WORK',
            ];
        }

        return $fields;
    }

    /** Подготовка полей сделки. */
    protected function buildDealFields(Event $event, int $contactId, array $payload = []): array
    {
        $uf = config('bitrix24.uf_codes');
        $fields = [
            'TITLE' => $payload['deal_title'] ?? 'Сделка по заявке с мероприятия',
            'CATEGORY_ID' => (int) ($payload['deal_category_id'] ?? config('bitrix24.deal_category_id')),
            'CURRENCY_ID' => 'RUB',
            'OPPORTUNITY' => (float) ($payload['deal_opportunity'] ?? 0),
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            'ASSIGNED_BY_ID' => (int) config('bitrix24.assigned_by_id'),
            'CONTACT_IDS' => [$contactId],
            'OPENED' => 'Y',
            'CLOSED' => 'N',
            'COMMENTS' => $payload['deal_comments'] ?? 'Создано из формы регистрации',
            'SOURCE_ID' => config('bitrix24.source_id', 'WEB'),
            'SOURCE_DESCRIPTION' => config('bitrix24.source_description', 'Регистрация на мероприятие'),
            'ADDITIONAL_INFO' => $payload['deal_additional_info'] ?? 'Создано автоматически',
            'UTM_SOURCE' => $payload['utm_source'] ?? null,
            'UTM_MEDIUM' => $payload['utm_medium'] ?? null,
            'UTM_CAMPAIGN' => $payload['utm_campaign'] ?? null,
            'UTM_CONTENT' => $payload['utm_content'] ?? null,
            'UTM_TERM' => $payload['utm_term'] ?? null,
            $uf['city'] ?? 'UF_CRM_ITS_DICT_CITY' => $payload['city'] ?? ($event->location ?: null),
            $uf['post'] ?? 'UF_CRM_POST' => $payload['position'] ?? null,
            $uf['specialization'] ?? 'UF_CRM_1642077639' => $payload['specialization'] ?? null,
            $uf['format'] ?? 'UF_CRM_1719829830428' => $payload['format'] ?? $event->format,
        ];
        return $fields;
    }

    /** Подготовка полей элемента смарт‑процесса «Мероприятие». */
    protected function buildEventItemFields(Event $event, int $contactId, ?int $dealId, array $payload = []): array
    {
        $eventUf = config('bitrix24.event_uf_fields');
        $dateKey = $this->normalizeUfKey($eventUf['date_start'] ?? null);
        $formatKey = $this->normalizeUfKey($eventUf['format'] ?? null);
        $speakersKey = $this->normalizeUfKey($eventUf['speakers'] ?? null);
        $statusKey = $this->normalizeUfKey($eventUf['status'] ?? null);
        $fields = [
            'title' => $payload['event_title'] ?? $event->title,
            'contactId' => $contactId,
            'comments' => $payload['event_comments'] ?? 'Регистрация с лендинга',
            'assignedById' => (int) config('bitrix24.assigned_by_id'),
        ];
        if ($dealId) {
            $fields['parentId2'] = $dealId;
        }
        if (!empty($dateKey) && !empty($event->start_date)) {
            // Формат: ISO 8601 с часовым поясом (Europe/Moscow), пример: 2025-08-21T10:00:00+03:00
            $dateStr = is_string($event->start_date) ? $event->start_date : $event->start_date->format('Y-m-d');
            $time = $event->start_time ?: '00:00:00';
            if (strlen($time) === 5) {
                $time .= ':00';
            }
            $tz = config('app.timezone', 'Europe/Moscow');
            $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $time, $tz);
            $fields[$dateKey] = $dt->toIso8601String();
        }
        if (!empty($formatKey) && !empty($event->format)) {
            // Отправляем формат по-русски если возможно
            $formatRu = match (strtolower((string) $event->format)) {
                'online' => 'Онлайн',
                'offline' => 'Офлайн',
                'hybrid' => 'Гибридный',
                default => (string) $event->format,
            };
            $fields[$formatKey] = $formatRu;
        }
        if (!empty($speakersKey)) {
            // Строка со списком спикеров
            $speakers = $event->speakers->pluck('full_name')->filter()->implode(', ');
            if (!empty($speakers)) {
                $fields[$speakersKey] = $speakers;
            }
        }
        // Статус оплаты в смарт-процессе, если поле задано: «Ожидает оплаты» при регистрации
        if (!empty($statusKey)) {
            $fields[$statusKey] = $payload['event_status'] ?? 'Ожидает оплаты';
        }
        return $fields;
    }

    /**
     * Нормализует код UF поля из вида UF_CRM_15_DATE_START в ufCrm15DateStart.
     * Если уже camelCase — возвращает как есть.
     */
    private function normalizeUfKey(?string $code): ?string
    {
        if (!$code) {
            return null;
        }
        if (str_contains($code, '_')) {
            $parts = array_map('strtolower', explode('_', $code));
            $first = array_shift($parts);
            $camel = $first;
            foreach ($parts as $p) {
                $camel .= ucfirst($p);
            }
            return $camel;
        }
        return $code;
    }

    // ==================== Стадии сделки ====================

    /**
     * Перевести сделку в стадию по ключу из конфига (registered/paid).
     */
    public function moveDealStageByKey(int $dealId, string $key): bool
    {
        $stages = (array) config('bitrix24.deal_stages', []);
        $stageId = $stages[$key] ?? null;
        return $this->b24->moveDealToStage($dealId, $stageId);
    }

    /**
     * Найти первую сделку пользователя (по email/phone) и перевести в нужную стадию.
     */
    public function moveFirstDealStageForUser(User $user, string $key): bool
    {
        $contact = $this->b24->findContact($user->email, $user->phone);
        if (!$contact) {
            return false;
        }
        $contactId = (int) $contact['ID'];
        $deals = $this->b24->listDealsByContact($contactId);
        if (empty($deals)) {
            return false;
        }
        $dealId = (int) $deals[0]['ID'];
        return $this->moveDealStageByKey($dealId, $key);
    }
}


