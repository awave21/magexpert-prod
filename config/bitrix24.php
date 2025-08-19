<?php

return [
    // Базовый URL вебхука Bitrix24, например: https://example.bitrix24.ru/rest/41/xxxxxx
    'webhook_url' => env('BITRIX24_WEBHOOK_URL', ''),

    // Категория сделок (CATEGORY_ID)
    'deal_category_id' => (int) env('BITRIX24_DEAL_CATEGORY_ID', 0),

    // Тип сделки (TYPE_ID)
    'deal_type_id' => env('BITRIX24_DEAL_TYPE_ID', 'SALE'),

    // entityTypeId смарт-процесса «Мероприятие»
    'event_entity_type_id' => (int) env('BITRIX24_EVENT_ENTITY_TYPE_ID', 1040),

    // Ответственный по умолчанию (ASSIGNED_BY_ID)
    'assigned_by_id' => (int) env('BITRIX24_ASSIGNED_BY_ID', 41),

    // Источник и описание источника
    'source_id' => env('BITRIX24_SOURCE_ID', 'WEB'),
    'source_description' => env('BITRIX24_SOURCE_DESCRIPTION', 'Регистрация на мероприятие'),

    // Коды пользовательских полей (UF_*) в CRM (контакт/сделка)
    'uf_codes' => [
        // Город (пример: UF_CRM_ITS_DICT_CITY)
        'city' => env('BITRIX24_UF_CITY', 'UF_CRM_ITS_DICT_CITY'),
        // Должность (пример: UF_CRM_POST)
        'post' => env('BITRIX24_UF_POST', 'UF_CRM_POST'),
        // Специализация (пример: UF_CRM_1642077639)
        'specialization' => env('BITRIX24_UF_SPECIALIZATION', 'UF_CRM_1642077639'),
        // Формат участия (пример: UF_CRM_1719829830428)
        'format' => env('BITRIX24_UF_FORMAT', 'UF_CRM_1719829830428'),
    ],

    // Коды стадий воронки сделок (STAGE_ID). Задаются в формате Bitrix24, например: C5:NEW, C5:PREPAYMENT_INVOICE, C5:WON
    'deal_stages' => [
        'registered' => env('BITRIX24_DEAL_STAGE_REGISTERED', ''), // стадия после регистрации (до оплаты)
        'paid' => env('BITRIX24_DEAL_STAGE_PAID', ''), // стадия после успешной оплаты
    ],

    // Карта полей «Мероприятие» (смарт‑процесс) — укажите фактические UF_* коды
    'event_uf_fields' => [
        // Дата и время начала
        'date_start' => env('BITRIX24_EVENT_UF_DATE_START', null), // пример: UF_CRM_15_DATE_START
        // Формат (Онлайн/Офлайн)
        'format' => env('BITRIX24_EVENT_UF_FORMAT', null), // пример: UF_CRM_15_FORMAT
        // Спикеры
        'speakers' => env('BITRIX24_EVENT_UF_SPEAKERS', null), // пример: UF_CRM_15_SPEAKERS
        // Статус оплаты: "Оплачено" / "Ожидает оплаты"
        'status' => env('BITRIX24_EVENT_UF_STATUS', 'UF_CRM_15_STATUS'),
    ],

    // Настройки повторов/таймаутов для HTTP
    'http' => [
        'retry_times' => (int) env('BITRIX24_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('BITRIX24_RETRY_SLEEP_MS', 250),
        'timeout' => (int) env('BITRIX24_TIMEOUT', 15),
        'connect_timeout' => (int) env('BITRIX24_CONNECT_TIMEOUT', 5),
    ],

    // Уникальность элемента «Мероприятие»: проверять перед созданием через crm.item.list
    'event_unique' => (bool) env('BITRIX24_EVENT_UNIQUE', false),

    // Канал логирования
    'log_channel' => env('BITRIX24_LOG_CHANNEL', 'bitrix24'),
];


