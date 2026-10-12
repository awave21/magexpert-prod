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
        // база «Пользователи MagExpert» в Sender: сайт держит её в актуальном виде только через открытый API Sender
        // (SENDER_API_URL + SENDER_API_KEY), как сторонний сервис. Первичная загрузка — php artisan sender:sync-users.
        'users_list_name' => env('SENDER_USERS_LIST_NAME', 'Пользователи MagExpert'),
        // ID базы в Sender; пусто — база ищется (или создаётся) по названию один раз, ID запоминается в кеше
        'users_list_id' => env('SENDER_USERS_LIST_ID'),
        // передавать ли в базу регистрации, изменения профиля, галочку рассылки и удаления пользователей (через очередь)
        'users_sync' => (bool) env('SENDER_USERS_SYNC', false),
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
    // ящик для отчётов о жалобах (FBL Mail.ru и Яндекса): sender:complaints --fetch раз в 10 минут
    'fbl' => [
        'host' => env('SENDER_FBL_HOST'),
        'port' => (int) env('SENDER_FBL_PORT', 993),
        'username' => env('SENDER_FBL_USERNAME'),
        'password' => env('SENDER_FBL_PASSWORD'),
        'folder' => env('SENDER_FBL_FOLDER', 'INBOX'),
    ],
    // прогрев IP: с даты SENDER_WARMUP_START письма всех рассылок ограничены по дням графиком (писем в сутки),
    // после последнего дня ограничение снимается. Пусто — прогрева нет.
    'warmup' => [
        'start' => env('SENDER_WARMUP_START'),
        'schedule' => array_map('intval', array_filter(explode(',', (string) env('SENDER_WARMUP_SCHEDULE', '200,200,500,500,1000,1000,1000,2000,2000,2000,3500,3500,3500,3500')))),
    ],
];
