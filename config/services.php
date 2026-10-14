<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // вход через Яндекс ID и VK ID (Socialite)
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => '/auth/yandex/callback',
        // включите, когда в приложении Яндекса разрешён «Доступ к номеру телефона»
        'phone' => (bool) env('YANDEX_PHONE', false),
    ],

    'vkid' => [
        // одно приложение VK ID на id.vk.com/business: через него же входят Одноклассники и Mail.ru
        'client_id' => env('VKID_CLIENT_ID'),
        'client_secret' => env('VKID_CLIENT_SECRET'),
        'redirect' => '/auth/vkid/callback',
        // включите, когда в приложении VK ID разрешён доступ к телефону
        'phone' => (bool) env('VKID_PHONE', false),
    ],

    'dadata' => [
        'token' => env('DADATA_TOKEN'),
        'secret' => env('DADATA_SECRET'),
    ],

    // ИИ-помощник в кабинете: rag — поиск по документам библиотеки; demo — тестовые ответы (в продакшене не включается)
    'sender' => [
        // секрет вебхука Sender: им подписано тело событий отписки и жалоб
        'webhook_secret' => env('SENDER_WEBHOOK_SECRET'),
    ],

    'assistant' => [
        'driver' => env('ASSISTANT_DRIVER'),
        // фрагменты с косинусной близостью к вопросу ниже порога в поиск по смыслу не попадают
        'min_similarity' => (float) env('ASSISTANT_MIN_SIMILARITY', 0.25),
        // журнал вопросов и открытий документов хранится столько месяцев, затем удаляется (model:prune)
        'history_months' => (int) env('ASSISTANT_HISTORY_MONTHS', 12),
        // цены ИИ-шлюза Beget, ₽ за 1 млн токенов [вход, выход]: для оценки расходов в админке
        'prices' => [
            'gpt-6-luna' => [12, 60],
            'deepseek-v4-flash' => [10, 20],
            'gemini-3.1-flash-lite' => [30, 179],
            'text-embedding-3-large' => [16, 0],
        ],
    ],

];
