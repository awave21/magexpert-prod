<?php

return [
    'connection' => env('SENDER_DB_CONNECTION', 'sender'),
    'queue' => env('SENDER_QUEUE', 'sender'),
    'max_attempts' => (int) env('SENDER_MAX_ATTEMPTS', 3),
    'dkim_selector' => env('SENDER_DKIM_SELECTOR', 'mail'),
    'server_ip' => env('SENDER_SERVER_IP'),
    'client' => [
        'driver' => env('SENDER_CLIENT_DRIVER', 'local'),
        'organization' => env('SENDER_ORGANIZATION', 'magexpert'),
        'url' => env('SENDER_API_URL'),
        'api_key' => env('SENDER_API_KEY'),
        'from_address' => env('SENDER_FROM_ADDRESS', 'noreply@mag-expert.ru'),
        'from_name' => env('SENDER_FROM_NAME', 'МедАльянсГрупп Expert'),
    ],
    'mail_host' => env('SENDER_MAIL_HOST', 'mail.mag-expert.ru'),
];
