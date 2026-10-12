Задача: выложить на этот VPS свежий `main` проекта MagExpert (обычно `/var/www/magexpert`) и включить ИИ-помощника в личном кабинете. В релизе:
- сайт переходит с Laravel 11 на Laravel 12 (зависимости обновлены, все тесты проходят);
- пакет `laravel/ai` и ИИ-шлюз Beget (`BEGET_AI_URL`, `BEGET_AI_KEY` уже есть в `.env`);
- пункт меню «ИИ-помощник» (`/cabinet/assistant`), доступный тем, у кого есть оплаченный или выданный доступ к мероприятию;
- таблицы поиска `library_indexes` и `library_chunks` (pgvector, расширение `vector` в базе уже создано);
- команда `php artisan library:import`: загружает фрагменты документов библиотеки, подготовленные на Mac через Docling, и считает векторы через шлюз;
- фоновая задача `IndexLibraryDocument`: новый PDF из админки сам попадает в поиск (разбор через `pdftotext`).

## Правила
- Работай по шагам, после каждого коротко покажи результат.
- Перед необратимыми действиями и перед любым изменением системных настроек показывай, что собираешься сделать, и жди моего «да».
- Пароли, ключи, токены и значения из `.env` в чат не выводи, только имена переменных.
- Без моего подтверждения не трогай nginx, php-fpm, файрвол, Postfix, OpenDKIM, конфиг Redis и supervisor.
- Не делай `git push`, не создавай и не удаляй ветки. Сервер работает только с `main`.
- Если команда упала, остановись, покажи ошибку и предложи, что делать. Не чини «на ходу» без согласования.

## 0. Разведка (ничего не меняя)
1. Путь к проекту и владелец папки (как в прошлый раз). Команды artisan, composer и npm запускай от владельца.
2. `git status`, `git log --oneline -1`. Если есть незакоммиченные изменения, остановись и покажи их.
3. Версии: `php -v`, `composer -V`, `node -v`, `npm -v`. Память: `free -h`, `swapon --show`.
4. `php -i | grep -E '^memory_limit'` для CLI и `memory_limit`, `max_execution_time` для php-fpm (из `/etc/php/8.4/fpm/php.ini` или пула).
5. Таймауты: `grep -R "fastcgi_read_timeout" /etc/nginx/` и `grep -R "request_terminate_timeout" /etc/php/8.4/fpm/`. Ответ помощника занимает 5–15 секунд, нужен запас не меньше 60 секунд. Только покажи значения.
6. Воркеры: `supervisorctl status` и какие очереди они слушают. Задача индексации идёт в очередь `default`.
7. В `.env` только наличие имён: `BEGET_AI_URL`, `BEGET_AI_KEY`, `ASSISTANT_DRIVER`, `QUEUE_CONNECTION`.
8. Сводка и жди моего «да».

## 1. Резервные копии
- `pg_dump` основной базы в `~/backups/` с датой в имени, покажи размер.
- Копия `.env`: `cp .env ~/backups/.env-$(date +%F-%H%M) && chmod 600 ~/backups/.env-*`.
- Запомни текущий коммит (`git rev-parse HEAD`) для отката.

## 2. Swap (по желанию)
Свопа нет, а памяти 2,9 ГБ. `composer install` и сборка фронта могут упереться в память. Предложи команды для swap-файла на 2 ГБ (`fallocate`, `mkswap`, `swapon`, строка в `/etc/fstab`) и выполни только после моего «да».

## 3. Код
```
git fetch origin
git pull --ff-only origin main
composer check-platform-reqs --no-dev
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```
Laravel 12 требует PHP 8.2+, у нас 8.4, так что всё должно сойтись. Если `check-platform-reqs` что-то покажет, остановись.

## 4. Миграции
```
php artisan migrate --force
```
Новая миграция `2026_10_12_220329_create_library_index_tables`. Расширение `vector` уже создано, поэтому права суперпользователя не нужны. Проверь: `php artisan tinker --execute="echo Schema::hasTable('library_chunks') ? 'есть' : 'нет';"`.

## 5. `.env`
Покажи имена, которые добавишь, и дождись «да»:
```
ASSISTANT_DRIVER=rag
```
`BEGET_AI_URL` и `BEGET_AI_KEY` уже есть. Модели по умолчанию: ответы `gpt-6-luna`, векторы `text-embedding-3-large`. Менять их не нужно.

Затем:
```
php artisan optimize
php artisan queue:restart
```
Если php-fpm с opcache — `sudo systemctl reload php8.4-fpm` (после моего «да»).

## 6. Загрузка библиотеки в поиск
1. Я загружу файл командой `scp` в домашнюю папку пользователя, под которым ты работаешь: `~/library-chunks.json` (около 13 МБ). Дождись, пока я скажу, что загрузил.
2. Пробный прогон, без запросов к шлюзу:
   ```
   php artisan library:import ~/library-chunks.json --dry-run
   ```
   Покажи хвост таблицы и итог. Ожидаю 57 документов и около 1,69 млн токенов, ни одной строки «PDF на сайте другой» или «не найден». Если такие есть, покажи их и остановись.
3. После моего «да» — настоящий импорт. Это около 27 ₽ и 5–10 минут, поэтому запусти так, чтобы обрыв SSH его не прервал:
   ```
   nohup php artisan library:import ~/library-chunks.json > ~/library-import.log 2>&1 &
   ```
   Следи по `tail -f ~/library-import.log`. Повторный запуск безопасен: готовые документы пропускаются.
4. Проверка: `php artisan tinker --execute="echo App\Models\LibraryIndex::count().' док., '.App\Models\LibraryChunk::count().' фрагм.';"`. Ожидаю 57 и 4270.

## 7. Проверка
- `curl -sI https://mag-expert.ru/` → `200`.
- Вопрос через консоль (стоит меньше копейки):
  ```
  php artisan tinker --execute="\$a = app(App\Services\Assistant\LibraryAssistant::class)->answer('В каком возрасте рекомендуется вакцинация от ВПЧ?'); echo \$a->text, PHP_EOL; foreach (\$a->sources as \$s) echo \$s['number'], '. ', \$s['title'], ', стр. ', \$s['page'], PHP_EOL;"
  ```
  Покажи ответ и источники.
- Я открою `/cabinet/assistant` в кабинете и задам вопрос сам. Если запрос оборвётся по таймауту, покажи `tail -50 storage/logs/laravel.log` и лог nginx.
- Задача индексации новых PDF: проверь, что `failed_jobs` пуст после следующей загрузки документа в админке.

## Откат
```
git checkout <коммит из шага 1>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```
Убери `ASSISTANT_DRIVER` из `.env`, затем `php artisan optimize` и `php artisan queue:restart`. Таблицы `library_*` откатывать не нужно: старый код их не использует.

## Что вернуть мне в конце
Коротко: коммит до и после, версия Laravel (`php artisan --version`), выполненные миграции, что изменено в `.env` (только имена), итог импорта (документов и фрагментов, токены), ответ на тестовый вопрос, значения таймаутов nginx и php-fpm, включён ли swap, состояние воркеров и что осталось сделать мне. Напомни удалить копию `.env` из `~/backups/`, когда она станет не нужна.
