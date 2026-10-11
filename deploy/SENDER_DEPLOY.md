Задача: выложить на мой VPS ветку `feature/sender` проекта MagExpert (Laravel). В ней сервис отправки писем Sender: сайт перестаёт отправлять письма через Sendsay и отправляет их сам через локальный Postfix. Ещё в ветке админка Sender по адресу `/sender`. Postfix и OpenDKIM на сервере уже настроены и отправляют письма (селектор DKIM `mail`, ключ `/etc/opendkim/keys/mag-expert.ru/mail.private`).

Работай по шагам. После каждого шага коротко покажи результат. Перед необратимыми действиями спрашивай меня. Пароли, ключи и содержимое `.env` в чат не выводи, только имена переменных.

## 0. Разведка (ничего не меняя)
- Где лежит проект: найди по конфигу nginx (`grep -R "root " /etc/nginx/sites-enabled/`) и покажи путь. Дальше все команды выполняются в этой папке и от того пользователя, которому она принадлежит.
- `git status`, `git branch --show-current`, `git log --oneline -3`. Если есть незакоммиченные изменения на сервере, остановись и покажи их мне.
- Версии: `php -v`, `composer -V`, `node -v`, `npm -v`, `psql --version`.
- Как запущены воркеры очереди: `supervisorctl status` и `systemctl list-units | grep -i -E "queue|horizon|worker"`. Покажи команду воркера, в частности параметр `--queue`.
- В `.env` покажи только значения `QUEUE_CONNECTION`, `DB_CONNECTION`, `DB_HOST`, `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` и есть ли переменные `SENDER_*`.

## 1. Резервная копия
- Дамп основной базы: `pg_dump` в `~/backups/magexpert-$(date +%F-%H%M).sql.gz`. Покажи размер файла.
- Запомни текущий коммит (`git rev-parse HEAD`), он нужен для отката.

## 2. База для Sender
Создай пустую базу `magexpert_sender` в том же Postgres, владелец тот же пользователь, что в `DB_USERNAME`. Покажи команду перед выполнением.

## 3. Код
```
git fetch origin
git checkout feature/sender
composer install --no-dev --optimize-autoloader
npm ci && npm run build
npm --prefix sender-ui ci && npm --prefix sender-ui run build
```
Последняя команда собирает админку в `public/sender-static`.

## 4. Переменные в `.env`
Добавь (значения паролей не показывай):
```
SENDER_DB_DATABASE=magexpert_sender
SENDER_CLIENT_DRIVER=local
SENDER_ORGANIZATION=magexpert
SENDER_FROM_ADDRESS=noreply@mag-expert.ru
SENDER_FROM_NAME="МедАльянсГрупп Expert"
SENDER_SERVER_IP=62.217.181.42
SENDER_MAIL_HOST=mail.mag-expert.ru
```
И переключи почту Laravel на локальный Postfix (старые значения `MAIL_*` сохрани в комментарии для отката):
```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_SCHEME=null
MAIL_AUTO_TLS=false
MAIL_FROM_ADDRESS=noreply@mag-expert.ru
```
`MAIL_AUTO_TLS=false` нужен потому, что Postfix на этом сервере отдаёт самоподписанный сертификат на имя хоста, а Laravel подключается к 127.0.0.1 и без этой строки обрывает соединение («Peer certificate CN … did not match»). Шифрование внутри одного сервера не нужно.

## 5. Миграции и начальные данные
```
php artisan migrate --force
php artisan migrate --database=sender --path=app/Sender/Database/Migrations --force
php artisan sender:install-defaults
php artisan sender:domain mag-expert.ru
sudo php artisan sender:dkim-import mag-expert.ru /etc/opendkim/keys/mag-expert.ru/mail.private --selector=mail
```
- Первая команда обновляет основную базу сайта, вторая создаёт таблицы Sender в `magexpert_sender`: обычный `migrate` миграции Sender не видит. Покажи список выполненных миграций. Миграция `2025_07_18_065100_create_categories_table` должна пропуститься без ошибок: таблица уже есть.
- `sender:domain` выведет таблицу DNS-записей. Покажи мне строку `verification`: мне нужно внести TXT-запись `_sender-verify.mag-expert.ru` в Beget. DKIM, SPF и DMARC уже внесены.
- `sender:dkim-import` записывает в Sender тот же ключ, которым подписывает OpenDKIM. Если `sudo` меняет пользователя и ломает права на `storage`, сначала скопируй ключ во временный файл с правами пользователя проекта, импортируй и удали копию.
- Когда я скажу, что TXT-запись внесена: `php artisan sender:domain mag-expert.ru --verify`. Нужен статус `verified`.

## 6. Пользователь админки
`php artisan sender:user-create <мой email> --name="<имя>"`: спроси у меня email и имя. Пароль сгенерируется. **Не пиши его в чат**, скажи мне посмотреть его в терминале.

## 7. Воркеры очередей
Письма Sender идут в две очереди Redis: служебные письма сайта (сброс пароля, подтверждение почты) — в `sender-transactional`, рассылки, подготовка баз и проверка адресов — в `sender`. Каждую очередь слушает свой процесс: тогда сброс пароля уходит за секунды, даже пока рассылочный воркер занят долгой задачей (подготовка базы, проверка адресов). Если очередь `sender-transactional` никто не слушает, письма сайта висят в статусе «В очереди».

Конфиг supervisor, например `/etc/supervisor/conf.d/sender.conf` (путь к проекту и пользователя взять из шага 0, покажи конфиг мне перед применением):
```
[program:sender-transactional]
command=php /var/www/magexpert/artisan queue:work redis --queue=sender-transactional --sleep=1 --tries=1 --max-time=3600
user=<пользователь проекта>
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/www/magexpert/storage/logs/sender-transactional.log

[program:sender-campaigns]
command=php /var/www/magexpert/artisan queue:work redis --queue=sender,default --sleep=3 --tries=1 --max-time=3600
user=<пользователь проекта>
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/www/magexpert/storage/logs/sender-campaigns.log
```
- Если уже есть общий воркер сайта со своим `--queue` (например, `default`), убери из него очереди `sender` и `sender-transactional`, если они там есть: их теперь слушают процессы выше. Очередь `default` можно оставить в общем воркере или в `sender-campaigns`, но не в обоих.
- `--tries=1`: повторы делает сама задача. `--sleep=1` у служебных писем: пустую очередь воркер опрашивает раз в секунду.
- Задачи короткие (до 80 секунд) и укладываются в `retry_after` Redis (90 секунд), поэтому один и тот же процесс можно масштабировать `numprocs` без двойной обработки.
- Применить: `supervisorctl reread && supervisorctl update`, проверить: `supervisorctl status` — оба процесса `RUNNING`.

## 8. Кэши и перезапуск
```
php artisan optimize
php artisan queue:restart
```
Если используется php-fpm с opcache, перезагрузи его (`systemctl reload php8.x-fpm`).

## 9. Проверка
- `curl -sI https://mag-expert.ru/sender` и `curl -sI https://mag-expert.ru/sender/domains`: оба 200, `content-type: text/html`.
- Я войду в админку и запрошу сброс пароля на сайте на свой адрес. Проверь:
  - `php artisan tinker --execute="echo App\Sender\Models\Message::latest('id')->first()?->status;"` должно стать `sent`;
  - в `/var/log/mail.log` у этого письма `status=sent`.
- Когда я пришлю заголовки письма, проверь `Authentication-Results` (spf, dkim, dmarc pass).

## Откат
```
git checkout <коммит из шага 1>
composer install --no-dev --optimize-autoloader && npm ci && npm run build
```
Затем верни старые `MAIL_*` в `.env`, выполни `php artisan optimize` и `php artisan queue:restart`. База `magexpert_sender` откату не мешает, её можно оставить.

## Ограничения
- Не трогай nginx, файрвол, Postfix и OpenDKIM без моего подтверждения.
- Не меняй MX и DNS: записи я вношу сам в Beget.
- Не делай `git push`, не меняй ветки в репозитории.

## Обновление после новых коммитов
Если обновление приносит плавную отправку рассылок (миграция `2026_10_10_000018_add_schedule_to_sender_campaigns_table`), до `queue:restart`:
- настрой два процесса supervisor из шага 7 (`sender-transactional` и `sender-campaigns`), иначе письма сайта перестанут уходить;
- перед миграцией проверь, что в рассылках нет повторных адресов, иначе уникальный индекс не создастся:
  `php artisan tinker --execute="echo App\Sender\Models\Message::query()->whereNotNull('campaign_id')->selectRaw('campaign_id, to_email')->groupBy('campaign_id','to_email')->havingRaw('count(*) > 1')->count();"` должно быть `0`;
- в `.env`: `SENDER_HOURLY_LIMIT` — сколько писем всех рассылок в час можно отдать Postfix (на время прогрева IP, например 300; пусто — без лимита). Регистрация новых организаций в админке выключена, включает её `SENDER_REGISTRATION=true`.
- после выкладки `php artisan schedule:list` должен показывать `sender:campaigns-tick`: без планировщика плавные и отложенные рассылки не начнутся.

```
git pull --ff-only origin feature/sender
composer install --no-dev --optimize-autoloader
npm --prefix sender-ui ci && npm --prefix sender-ui run build
php artisan migrate --force
php artisan migrate --database=sender --path=app/Sender/Database/Migrations --force
php artisan storage:link
php artisan optimize && php artisan queue:restart
```

## Статусы доставки, открытия и очистка
Статусы «Доставлено / Не доставлено» Sender берёт из журнала Postfix, а старые письма удаляет сам (срок — `SENDER_RETENTION_DAYS`, по умолчанию 365 дней, он указан в политике). Очередные пачки плавных рассылок раз в минуту ставит `sender:campaigns-tick`. Все задачи запускает планировщик Laravel.
- Пользователь, от которого работает проект, должен читать журнал: `sudo usermod -aG adm <пользователь>` и перелогиниться. Проверка: `php artisan sender:mail-log` должна вывести «Прочитано строк: …».
- В cron этого пользователя должна быть строка планировщика: `* * * * * cd /var/www/magexpert && php artisan schedule:run >> /dev/null 2>&1`. Проверка: `php artisan schedule:list` показывает `sender:campaigns-tick`, `sender:mail-log` и `sender:prune`.

## Алерты в Telegram
`sender:health` раз в 5 минут проверяет: служебные письма висят в очереди дольше 5 минут, письма рассылок дольше 30 минут, письма застряли в статусе «отправляется», планировщик не запускает `sender:campaigns-tick`, в очереди Postfix больше 500 писем, отказов за час больше 5%, за час больше 20 ошибок отправки, IP сервера в чёрных списках (Spamhaus, SpamCop, Barracuda, PSBL). О новой проблеме пишет сразу, о нерешённой напоминает раз в 6 часов, об исправленной сообщает.
- Создай бота у @BotFather, добавь его в нужный чат, узнай `chat_id` (например, через `https://api.telegram.org/bot<токен>/getUpdates` после сообщения в чате). Токен в чат со мной не пиши.
- В `.env`: `SENDER_TELEGRAM_BOT_TOKEN`, `SENDER_TELEGRAM_CHAT_ID`, `SENDER_SERVER_IP` (для проверки чёрных списков). Без токена задача в расписание не попадает.
- Чтобы считать очередь Postfix, пользователь проекта должен запускать `/usr/sbin/postqueue -j` (обычно можно без sudo).
- Проверка без отправки: `php artisan sender:health --print`.

## Организации и лимиты
`php artisan sender:organization` — список организаций, их дневной лимит и сколько отправлено сегодня. `php artisan sender:organization <slug> --daily-limit=1000` задаёт лимит писем в сутки (служебные и рассылки вместе), `--no-limit` снимает, `--deactivate` / `--activate` отключает и включает организацию. Для `magexpert` лимит не нужен: служебные письма сайта не должны блокироваться.

## База «Пользователи сайта»
В админке: «Подписчики» → «Новая база» → «Пользователи сайта с согласием на рассылку». База повторяет пользователей с включённой галочкой «Письма о мероприятиях», обновляется кнопкой и сама перед каждой рассылкой. В письме доступны `{{ name }}`, `{{ specialization }}`, `{{ city }}`, `{{ company }}`, `{{ position }}`.

## Жалобы на спам (FBL)
Mail.ru и Яндекс присылают отчёт, когда получатель нажимает «Это спам». Sender блокирует такой адрес, отписывает его от рассылок и снимает галочку на сайте; жалобы видны в отчёте рассылки.
- Заведи ящик для отчётов, например `fbl@mag-expert.ru` в Mail.ru для бизнеса, и включи в нём IMAP.
- Подключи домен в [Postmaster Mail.ru](https://postmaster.mail.ru) и включи FBL на этот ящик; в [Яндекс Постмастере](https://postmaster.yandex.ru) — тоже. Письма уже содержат нужные заголовки `Feedback-ID` и `X-Sender-Id`.
- В `.env`: `SENDER_FBL_HOST=imap.mail.ru`, `SENDER_FBL_USERNAME=fbl@mag-expert.ru`, `SENDER_FBL_PASSWORD` (пароль приложения). Тогда `sender:complaints --fetch` работает раз в 10 минут. Разово: `php artisan sender:complaints --fetch`, отчёт из файла: `php artisan sender:complaints report.eml`.

## Прогрев IP
`SENDER_WARMUP_START=2026-10-14` в `.env` включает график: письма всех рассылок в сутки ограничены по дням (по умолчанию 200, 200, 500, 500, 1000 ×3, 2000 ×3, 3500 ×4), потом ограничение снимается. Свой график: `SENDER_WARMUP_SCHEDULE=200,400,800,...`. Служебные письма в лимит не входят. Состояние: `php artisan sender:warmup`, в админке — плашка на странице «Рассылки».

## Надёжность
- `sender:recover` каждые 5 минут разбирает письма, зависшие в статусе «отправляется» после падения воркера: принятые Postfix отмечает отправленными, остальные ставит в очередь заново. Без доступа к журналу Postfix такие письма помечаются ошибкой, чтобы не отправить дважды.
- `sender:mail-log` после ротации журнала сначала дочитывает хвост `mail.log.1`.

## Команда и роли
Раздел «Команда» в админке: администратор (всё, включая домены, адреса отправителей, API-ключи, вебхуки, блокировки и команду), редактор (письма, базы, рассылки), просмотр. Там же журнал действий: запуск, пауза и отмена рассылок, удаление баз и писем, ключи, домены, блокировки, изменения команды. Из консоли: `php artisan sender:user-create <email> --role=editor`. Существующие пользователи после миграции — администраторы.

## Вебхуки
API админки `/api/sender/v1/admin/webhooks` (страницы в интерфейсе пока нет): адрес `https://`, события `message.delivered`, `message.bounced`, `message.complained`, `message.opened`, `message.clicked`, `recipient.unsubscribed` (пусто — все). Тело JSON, подпись в заголовке `X-Sender-Signature: sha256=<HMAC тела секретом>`; секрет показывается один раз при создании. Неудачная доставка повторяется через 1, 5, 15 и 60 минут.
