<?php

return [
    'connection' => env('SENDER_DB_CONNECTION', 'sender'),
    // очередь писем рассылок
    'queue' => env('SENDER_QUEUE', 'sender'),
    // очередь служебных писем (сброс пароля, подтверждение почты): воркер берёт её первой,
    // поэтому такие письма не ждут, пока уйдёт рассылка
    'transactional_queue' => env('SENDER_TRANSACTIONAL_QUEUE', 'sender-transactional'),
    // самостоятельная регистрация организаций: пока выключена, новых пользователей создаёт sender:user-create
    'registration' => (bool) env('SENDER_REGISTRATION', false),
    // сколько писем всех рассылок можно поставить в очередь за час: IP один на все рассылки, при прогреве его ограничивают.
    // Пусто или 0 — без ограничения. Служебные письма не считаются.
    'hourly_limit' => (int) env('SENDER_HOURLY_LIMIT', 0),
    // сколько запросов в минуту принимает API отправки от одной организации
    'api_per_minute' => (int) env('SENDER_API_PER_MINUTE', 300),
    // часовой пояс окна отправки рассылок
    'timezone' => env('SENDER_TIMEZONE', 'Europe/Moscow'),
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
        // какой шаблон отправлять для каждого письма сайта: ключ шаблона (алиас) или его ID из Sender
        'templates' => [
            'welcome' => env('SENDER_TEMPLATE_WELCOME', 'welcome'),
            'email_confirmation' => env('SENDER_TEMPLATE_EMAIL_CONFIRMATION', 'email-confirmation'),
            'password_reset' => env('SENDER_TEMPLATE_PASSWORD_RESET', 'password-reset'),
            'password_reset_link' => env('SENDER_TEMPLATE_PASSWORD_RESET_LINK', 'password-reset-link'),
            'event_registration' => env('SENDER_TEMPLATE_EVENT_REGISTRATION', 'event-registration'),
            'api_registration' => env('SENDER_TEMPLATE_API_REGISTRATION', 'api-registration'),
        ],
    ],
    'mail_host' => env('SENDER_MAIL_HOST', 'mail.mag-expert.ru'),
    // сколько дней хранить письма, открытия и клики (указано в политике обработки персональных данных)
    'retention_days' => (int) env('SENDER_RETENTION_DAYS', 365),
    // журнал Postfix: из него берутся статусы доставки (sender:mail-log)
    'mail_log' => env('SENDER_MAIL_LOG', '/var/log/mail.log'),
    // адрес админки на своём поддомене; пусто — админка открывается по /sender
    'ui_url' => env('SENDER_UI_URL'),
    // алерты в Telegram (sender:health раз в 5 минут): воркер, очередь Postfix, отказы, чёрные списки
    'alerts' => [
        'telegram_token' => env('SENDER_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('SENDER_TELEGRAM_CHAT_ID'),
        'postqueue' => env('SENDER_POSTQUEUE', '/usr/sbin/postqueue'),
        // писем в очереди Postfix, после которых это уже проблема
        'postfix_queue' => (int) env('SENDER_ALERT_POSTFIX_QUEUE', 500),
        // доля отказов за час, %
        'bounce_rate' => (float) env('SENDER_ALERT_BOUNCE_RATE', 5),
        'blacklists' => ['zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org', 'psbl.surriel.com'],
    ],
];
