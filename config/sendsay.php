<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sendsay API Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация для интеграции с Sendsay API
    |
    */

    'api_url' => env('SENDSAY_API_URL', 'https://api.sendsay.ru/general/api/v100/json/'),

    'login' => env('SENDSAY_LOGIN'),

    'sublogin' => env('SENDSAY_SUBLOGIN', 'api'),

    'password' => env('SENDSAY_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Manager Email Addresses
    |--------------------------------------------------------------------------
    |
    | Список email адресов руководителей для отправки уведомлений
    |
    */
    'manager_emails' => [
        'm.m@dd-donne.ru',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Draft IDs
    |--------------------------------------------------------------------------
    |
    | ID черновиков писем в Sendsay для различных типов уведомлений
    |
    */
    'draft_ids' => [
        'welcome' => env('SENDSAY_DRAFT_WELCOME', null),
        'password_reset' => env('SENDSAY_DRAFT_PASSWORD_RESET', null),
        'order_confirmation' => env('SENDSAY_DRAFT_ORDER_CONFIRMATION', null),
        'order_paid_customer' => env('SENDSAY_DRAFT_ORDER_PAID_CUSTOMER', null),
        'order_paid_managers' => env('SENDSAY_DRAFT_ORDER_PAID_MANAGERS', null),
        'promotional' => env('SENDSAY_DRAFT_PROMOTIONAL', null),
        'gift_certificate' => env('SENDSAY_DRAFT_GIFT_CERTIFICATE', null),
        'event_registration' => env('SENDSAY_DRAFT_EVENT_REGISTRATION', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Groups
    |--------------------------------------------------------------------------
    |
    | ID групп в Sendsay для различных категорий пользователей
    |
    */
    'groups' => [
        'customers' => env('SENDSAY_GROUP_CUSTOMERS', null),
        'subscribers' => env('SENDSAY_GROUP_SUBSCRIBERS', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Настройки для ограничения частоты запросов к API
    |
    */
    'rate_limiting' => [
        'delay_between_emails' => env('SENDSAY_DELAY_BETWEEN_EMAILS', 100), // миллисекунды
        'max_retries' => env('SENDSAY_MAX_RETRIES', 3),
        'retry_delay' => env('SENDSAY_RETRY_DELAY', 1000), // миллисекунды
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Настройки логирования для Sendsay
    |
    */
    'logging' => [
        'enabled' => env('SENDSAY_LOGGING_ENABLED', true),
        'level' => env('SENDSAY_LOGGING_LEVEL', 'info'), // debug, info, warning, error
        'log_requests' => env('SENDSAY_LOG_REQUESTS', false),
        'log_responses' => env('SENDSAY_LOG_RESPONSES', true),
    ],

];