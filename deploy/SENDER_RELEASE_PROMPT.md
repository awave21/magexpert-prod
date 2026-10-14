Задача: выложить на этот VPS свежий `main` проекта MagExpert (Laravel, обычно `/var/www/magexpert`). В релизе сервис писем Sender вместо Sendsay (служебные письма сайта и рассылки через локальный Postfix + OpenDKIM), админка Sender на mail.mag-expert.ru, новый личный кабинет и вход через Яндекс и ВК. Postfix и OpenDKIM на сервере уже настроены (селектор DKIM `mail`, ключ `/etc/opendkim/keys/mag-expert.ru/mail.private`). Sender мог уже стоять здесь в старой версии из ветки `feature/sender`; этой ветки больше нет, вся работа в `main`.

В этом релизе также: новая страница «Новая база» с сопоставлением колонок, подтверждение подписки (двойное согласие), роли «Владелец» и «Дизайнер» и раздел «Настройки», MCP-сервер для ИИ (`/mcp`), публичное API баз и подписчиков и синхронизация пользователей сайта в базу «Пользователи MagExpert» через это API (шаг 10а).

Подробная справка по каждому шагу — в `deploy/SENDER_DEPLOY.md` из `main` (до обновления кода её можно прочитать так: `git fetch origin && git show origin/main:deploy/SENDER_DEPLOY.md`).

## Правила
- Работай по шагам, после каждого коротко покажи результат.
- Перед необратимыми действиями и перед любым изменением системных настроек показывай, что собираешься сделать, и жди моего «да».
- Пароли, ключи, токены и значения из `.env` в чат не выводи, только имена переменных. Пароль Redis передавай через переменную окружения `REDISCLI_AUTH`, не в командной строке.
- Без моего подтверждения не трогай nginx, файрвол, Postfix, OpenDKIM, конфиг Redis и supervisor. DNS и MX я меняю сам в Beget.
- Не делай `git push`, не создавай и не удаляй ветки. Сервер работает только с `main`.
- Если команда упала, остановись, покажи ошибку и предложи, что делать. Не чини «на ходу» без согласования.

## 0. Разведка (ничего не меняя)
1. Путь к проекту по nginx (`grep -R "root " /etc/nginx/sites-enabled/`) и владелец папки (`stat -c %U <путь>`). Дальше команды artisan, composer и npm запускай от этого пользователя.
2. `git status`, `git branch --show-current`, `git log --oneline -3`. Если есть незакоммиченные изменения, остановись и покажи их.
3. Версии: `php -v`, `composer -V`, `node -v`, `npm -v`, `psql --version`, `redis-cli --version`.
4. Состояние Sender:
   - есть ли база `magexpert_sender` (`psql -lqt | cut -d'|' -f1 | grep -w magexpert_sender`);
   - если есть — `php artisan migrate:status --database=sender --path=app/Sender/Database/Migrations`;
   - воркеры: `supervisorctl status` и команды воркеров (`grep -R "queue:work" /etc/supervisor/conf.d/`), в частности их `--queue`;
   - планировщик: `crontab -l -u <владелец>` (нужна строка с `schedule:run`);
   - Redis: `redis-cli CONFIG GET appendonly`;
   - в `.env` только имена и наличие: `QUEUE_CONNECTION`, `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, все `SENDER_*`.
5. Сводка: что уже стоит, чего нет, какой путь выкладки — первая установка Sender или обновление. Жди моего «да».

## 1. Резервные копии
- `pg_dump` основной базы и, если есть, `magexpert_sender` в `~/backups/` с датой в имени, покажи размеры файлов.
- Копия `.env`: `cp .env ~/backups/.env-$(date +%F-%H%M) && chmod 600 ~/backups/.env-*`.
- Запомни текущий коммит (`git rev-parse HEAD`) для отката.

## 2. Код
```
git fetch origin
git checkout main
git pull --ff-only origin main
composer check-platform-reqs --no-dev
composer install --no-dev --optimize-autoloader
npm ci && npm run build
npm --prefix sender-ui ci && npm --prefix sender-ui run build
```
Последняя команда собирает админку Sender в `public/sender-static`.

## 3. База Sender и миграции
- Если базы `magexpert_sender` нет: создай её в том же Postgres с владельцем из `DB_USERNAME`. Покажи команду и дождись «да».
- Если таблицы Sender уже есть и миграция `2026_10_10_000018_add_schedule_to_sender_campaigns_table` ещё не выполнена, проверь, что в рассылках нет повторных адресов (иначе не создастся уникальный индекс). Нужен `0`:
  `php artisan tinker --execute="echo App\Sender\Models\Message::query()->whereNotNull('campaign_id')->selectRaw('campaign_id, to_email')->groupBy('campaign_id','to_email')->havingRaw('count(*) > 1')->count();"`
- Миграции:
```
php artisan migrate --force
php artisan migrate --database=sender --path=app/Sender/Database/Migrations --force
php artisan storage:link
```
- Новые миграции этого релиза: `2026_10_12_000021`–`000025` (подтверждение подписки, роли владельца и дизайнера, ключи MCP, настройка базы «Требовать подтверждение подписки», `password_set`). Миграция `000022` назначает владельцем каждой организации самого раннего активного администратора (если его нет — самого раннего активного участника). Проверь, кто владелец (только имя и email): `php artisan tinker --execute="\$o=App\\Sender\\Models\\Organization::where('slug','magexpert')->first()?->owner; echo \$o ? \$o->name.' '.\$o->email : 'нет владельца';"`. Если владельца нет, спроси меня, кого назначить, и выполни `php artisan sender:assign-owner magexpert <email>`.

## 4. Переменные `.env`
Покажи список имён, которые добавишь или поменяешь, и дождись «да». Старые `MAIL_*` сохрани комментарием для отката.
```
SENDER_DB_DATABASE=magexpert_sender
SENDER_CLIENT_DRIVER=local
SENDER_ORGANIZATION=magexpert
SENDER_SYSTEM_FROM_ADDRESS=noreply@mag-expert.ru
SENDER_SYSTEM_FROM_NAME="МедАльянсГрупп Expert"
SENDER_SERVER_IP=62.217.181.42
SENDER_MAIL_HOST=mail.mag-expert.ru
SENDER_UI_URL=https://mail.mag-expert.ru
SENDER_TRANSACTIONAL_QUEUE=sender-transactional
SENDER_REGISTRATION=false
SENDER_HOURLY_LIMIT=300

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_SCHEME=null
MAIL_AUTO_TLS=false
MAIL_FROM_ADDRESS=noreply@mag-expert.ru
```
- `SENDER_UI_URL` ставь, только если поддомен mail.mag-expert.ru уже открывается в nginx с HTTPS (`curl -sI https://mail.mag-expert.ru`). Иначе оставь пустым, админка будет на `/sender`.
- `SENDER_HOURLY_LIMIT=300` — ограничение рассылок на время прогрева IP.
- Необязательные, значения спроси у меня: `SENDER_TELEGRAM_BOT_TOKEN`, `SENDER_TELEGRAM_CHAT_ID` (алерты), `SENDER_FBL_HOST`, `SENDER_FBL_USERNAME`, `SENDER_FBL_PASSWORD` (жалобы на спам, IMAP), `SENDER_WARMUP_START` (дата начала прогрева, ГГГГ-ММ-ДД).

## 5. Начальные данные (только при первой установке Sender)
Если в `sender_organizations` уже есть `magexpert`, пропусти шаг и скажи об этом.
```
php artisan sender:install-defaults
php artisan sender:domain mag-expert.ru
```
- Из вывода `sender:domain` покажи мне строку `verification`: TXT `_sender-verify.mag-expert.ru` я внесу в Beget. Когда скажу, что внёс: `php artisan sender:domain mag-expert.ru --verify`, нужен `verified`.
- DKIM: `php artisan sender:dkim-import mag-expert.ru <файл ключа> --selector=mail`. Ключ читается от root: скопируй его во временный файл с правами владельца проекта, импортируй и удали копию (`shred -u`).
- Пользователь админки: спроси мой email и имя, затем `php artisan sender:user-create <email> --name="<имя>" --role=admin`. Пароль в чат не пиши, скажи мне посмотреть его в терминале.

## 6. Воркеры очередей (supervisor)
Служебные письма и рассылки обрабатывают два отдельных процесса. Подготовь `/etc/supervisor/conf.d/sender.conf` (путь и пользователя подставь из шага 0), покажи мне и после «да» примени:
```
[program:sender-transactional]
command=php /var/www/magexpert/artisan queue:work redis --queue=sender-transactional --sleep=1 --tries=1 --max-time=3600
user=<владелец>
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/www/magexpert/storage/logs/sender-transactional.log

[program:sender-campaigns]
command=php /var/www/magexpert/artisan queue:work redis --queue=sender,default --sleep=3 --tries=1 --max-time=3600
user=<владелец>
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/www/magexpert/storage/logs/sender-campaigns.log
```
- Если есть старый воркер, который слушает `sender` или `default`, предложи, как убрать эти очереди из него, чтобы `default` слушал ровно один процесс.
- `supervisorctl reread && supervisorctl update`, затем `supervisorctl status`: оба процесса `RUNNING`.

## 7. Планировщик и журнал Postfix
- В crontab владельца должна быть строка `* * * * * cd /var/www/magexpert && php artisan schedule:run >> /dev/null 2>&1`. Если её нет — покажи и после «да» добавь.
- Чтобы читать `/var/log/mail.log`, владелец должен быть в группе `adm`: `sudo usermod -aG adm <владелец>` (после «да»), затем перезапусти воркеры. Проверка: `php artisan sender:mail-log` выводит «Прочитано строк: …».
- `php artisan schedule:list` должен показать `sender:campaigns-tick`, `sender:recover`, `sender:prune`, `sender:mail-log`, а при настроенных Telegram и FBL ещё `sender:health` и `sender:complaints`.

## 8. Redis: очередь переживает перезапуск
Если `appendonly no` — после моего «да»:
1. `redis-cli CONFIG SET appendonly yes`, дождись `aof_rewrite_in_progress:0` в `redis-cli INFO persistence`.
2. Сделай копию `/etc/redis/redis.conf` и пропиши в нём `appendonly yes` и `appendfsync everysec`.
3. Перезапускать Redis не нужно. Проверь `CONFIG GET appendonly`.

## 9. Медленная отправка в Mail.ru, Яндекс и Gmail
Предложи запустить `sudo bash deploy/postfix/throttle.sh`: одно письмо в секунду на каждый из этих сервисов, копии конфигов Postfix сохраняются с суффиксом `.before-throttle`. Запускай только после моего «да». Проверка: `postconf -n | grep -E 'transport_maps|slow_'`.

## 10. Кэши и перезапуск
```
php artisan optimize
php artisan queue:restart
```
Если php-fpm с opcache — `sudo systemctl reload php8.x-fpm` (версию определи сам).

## 10а. Синхронизация пользователей сайта с Sender через API
Сайт работает с Sender как со сторонним сервисом: только через публичное API по ключу. Включаем по шагам, каждый — после моего «да».
1. API-ключ: я сам создам его в Sender («API-ключи» → «Создать ключ», название «Сайт MagExpert») и впишу в `.env`. Ключ в чат не присылаю и ты его не выводи.
2. В `.env` (покажи имена, жди «да»; старые значения сохрани комментарием):
```
SENDER_CLIENT_DRIVER=http
SENDER_API_URL=https://mag-expert.ru
SENDER_API_KEY=<впишу сам>
SENDER_USERS_LIST_NAME="Пользователи MagExpert"
SENDER_USERS_SYNC=false
```
   Затем `php artisan config:cache`. Важно: с `SENDER_CLIENT_DRIVER=http` служебные письма сайта (регистрация, сброс пароля) тоже идут через API — проверь их в шаге 11.
3. Перенос всех пользователей: `php artisan sender:sync-users --dry-run` (покажи таблицу: сколько с согласием и без), затем после «да» `php artisan sender:sync-users`. Покажи итоговую таблицу (Добавлено / Обновлено / Подписаны / Отписаны / С ошибками) и строки с ошибками, если есть. Пользователи без согласия попадают в базу со статусом «Отписан» и рассылок не получают.
4. Включить синхронизацию новых и изменённых пользователей: `SENDER_USERS_SYNC=true`, `php artisan config:cache`, `php artisan queue:restart`.
5. В Sender у базы «Пользователи MagExpert» настройка «Требовать подтверждение подписки» должна быть выключена (согласие дают галочкой на сайте): `php artisan tinker --execute="echo App\Sender\Models\ContactList::where('name','Пользователи MagExpert')->value('require_confirmation') ? 'включено' : 'выключено';"`.

## 11. Проверка
- `curl -sI https://mag-expert.ru/` и админка (`https://mail.mag-expert.ru` или `https://mag-expert.ru/sender`): `200`.
- `supervisorctl status`: оба воркера `RUNNING`.
- `php artisan sender:health --print`: «Проблем нет».
- `php artisan sender:warmup`: какой день прогрева или что он не включён.
- Я запрошу сброс пароля на сайте. Проверь, что последнее письмо стало `sent`, а затем `delivered`:
  `php artisan tinker --execute="echo App\Sender\Models\Message::latest('id')->first()?->status;"`
  и что в `/var/log/mail.log` у него `status=sent`.
- Когда я пришлю заголовки письма, разбери `Authentication-Results` (spf, dkim, dmarc должны быть pass).
- Синхронизация: я зарегистрирую тестового пользователя на сайте. Проверь, что задача `SyncUserToSender` выполнилась (`failed_jobs` пуст) и что адрес появился в базе: `php artisan tinker --execute="echo App\Sender\Models\Contact::where('email','<адрес>')->exists() ? 'есть' : 'нет';"`.
- MCP: `curl -s -o /dev/null -w "%{http_code}\n" -X POST https://mail.mag-expert.ru/mcp -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'` — ожидаю 401 (без ключа). Если 404 или 200 со страницей — nginx не пропускает POST на `/mcp` в Laravel, покажи мне конфиг, ничего не меняя.
- Страница подтверждения подписки: `curl -s -o /dev/null -w "%{http_code}\n" https://mail.mag-expert.ru/confirm-subscription/test` — ожидаю 404 («Ссылка недействительна»).

## Откат
```
git checkout <коммит из шага 1>
composer install --no-dev --optimize-autoloader && npm ci && npm run build
```
Верни `.env` из копии шага 1 (в том числе `SENDER_CLIENT_DRIVER` и `SENDER_USERS_SYNC=false`), `php artisan optimize`, `php artisan queue:restart`. Миграции 000021–000025 только добавляют поля и таблицы: откатывать их не нужно, старый код с ними работает. Процессы supervisor из шага 6 останови (`supervisorctl stop sender-transactional sender-campaigns`). Базу `magexpert_sender` не удаляй.

## Что вернуть мне в конце
Коротко: коммит до и после, выполненные миграции, кто стал владельцем организации, что изменено в `.env` (только имена), итог `sender:sync-users`, включена ли синхронизация, состояние воркеров, cron и Redis AOF, применён ли `throttle.sh`, результат проверки письма, синхронизации и MCP, и всё, что осталось сделать мне (DNS, значения переменных, PTR и т.п.).
