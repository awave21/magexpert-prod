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
MAIL_FROM_ADDRESS=noreply@mag-expert.ru
```

## 5. Миграции и начальные данные
```
php artisan migrate --force
php artisan sender:install-defaults
php artisan sender:domain mag-expert.ru
sudo php artisan sender:dkim-import mag-expert.ru /etc/opendkim/keys/mag-expert.ru/mail.private --selector=mail
```
- `migrate` создаёт таблицы и в основной базе, и в `magexpert_sender`. Покажи список выполненных миграций. Миграция `2025_07_18_065100_create_categories_table` должна пропуститься без ошибок: таблица уже есть.
- `sender:domain` выведет таблицу DNS-записей. Покажи мне строку `verification`: мне нужно внести TXT-запись `_sender-verify.mag-expert.ru` в Beget. DKIM, SPF и DMARC уже внесены.
- `sender:dkim-import` записывает в Sender тот же ключ, которым подписывает OpenDKIM. Если `sudo` меняет пользователя и ломает права на `storage`, сначала скопируй ключ во временный файл с правами пользователя проекта, импортируй и удали копию.
- Когда я скажу, что TXT-запись внесена: `php artisan sender:domain mag-expert.ru --verify`. Нужен статус `verified`.

## 6. Пользователь админки
`php artisan sender:user-create <мой email> --name="<имя>"`: спроси у меня email и имя. Пароль сгенерируется. **Не пиши его в чат**, скажи мне посмотреть его в терминале.

## 7. Воркер очереди
Письма Sender идут в очередь `sender`. Воркер должен её слушать, иначе письма будут висеть в статусе «В очереди».
- Если воркер уже есть, добавь `sender` в его `--queue`, например `--queue=sender,default`. Покажи изменение конфига перед применением.
- Если воркера нет, предложи конфиг supervisor: `php artisan queue:work redis --queue=sender,default --sleep=3 --tries=1 --max-time=3600` (повторы делает сама задача, поэтому `--tries=1`).
- Затем `supervisorctl reread && supervisorctl update`.

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
