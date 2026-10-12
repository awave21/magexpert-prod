Задача: подготовить этот VPS к чату по PDF-документам сайта MagExpert (Laravel, обычно `/var/www/magexpert`). Сам код чата пишется локально и придёт позже через `main`. Сейчас на сервере нужно только проверить ресурсы, поставить расширение pgvector в PostgreSQL, утилиту `pdftotext` и проверить доступ к ИИ-шлюзу Beget.

Как будет устроен чат: PDF из «Медицинской библиотеки» (таблица `medical_libraries`, файлы на диске `public`) режутся на фрагменты, для каждого считается вектор через ИИ-шлюз Beget (`https://api-llm.beget.com/v1`, совместим с OpenAI). Векторы хранятся в основной базе Postgres (pgvector). Ответы генерирует модель через тот же шлюз. Своих моделей на сервере не будет, нагрузка на память минимальная.

## Правила
- Работай по шагам, после каждого коротко покажи результат.
- Перед необратимыми действиями, установкой пакетов и любым изменением системных настроек показывай, что собираешься сделать, и жди моего «да».
- Пароли, ключи, токены и значения из `.env` в чат не выводи, только имена переменных.
- Код проекта не меняй: не делай `git pull`, `composer install`, миграции, не правь файлы в репозитории. Не делай `git push`, не создавай и не удаляй ветки.
- Без моего подтверждения не трогай nginx, файрвол, php-fpm, supervisor и конфиг PostgreSQL. Postgres не перезапускай.
- Если команда упала, остановись, покажи ошибку и предложи, что делать. Не чини «на ходу» без согласования.

## 0. Разведка (ничего не меняя)
1. Путь к проекту по nginx (`grep -R "root " /etc/nginx/sites-enabled/`) и владелец папки (`stat -c %U <путь>`). Команды artisan запускай от этого пользователя.
2. `git status`, `git branch --show-current`, `git log --oneline -1`.
3. Ресурсы: `free -h`, `swapon --show`, `df -h /`, `nproc`, пять самых прожорливых процессов по памяти (`ps aux --sort=-%mem | head -6`).
4. Версии: `php -v`, `composer -V`, `psql --version`, версия сервера Postgres (`sudo -u postgres psql -tAc "SHOW server_version;"`), ОС (`lsb_release -ds` или `cat /etc/os-release`).
5. Postgres:
   - стоит ли Postgres на этом же VPS (`systemctl status postgresql --no-pager | head -3`) и какой `DB_HOST` в `.env` (только локальный/нелокальный, без значения, если это не `127.0.0.1`/`localhost`);
   - имя основной базы из `DB_DATABASE` (имя базы можно показать);
   - доступно ли расширение: `sudo -u postgres psql -d <база> -tAc "SELECT name, default_version, installed_version FROM pg_available_extensions WHERE name='vector';"`;
   - является ли пользователь из `DB_USERNAME` суперпользователем: `sudo -u postgres psql -tAc "SELECT rolsuper FROM pg_roles WHERE rolname='<DB_USERNAME>';"`.
6. PDF-документы:
   - сколько записей по языкам: `php artisan tinker --execute="print_r(App\Models\MedicalLibrary::selectRaw('language, count(*) c')->groupBy('language')->pluck('c','language')->all());"`;
   - сколько файлов реально есть на диске и их общий размер: перебери `file_path` из `medical_libraries`, проверь существование в `storage/app/public/`, выведи «есть / нет файла», общий объём (МБ) и 5 самых больших файлов (имя и размер);
   - есть ли PDF и в мероприятиях: `php artisan tinker --execute="echo App\Models\Event::where('file_path','like','%.pdf')->count();"`.
7. Установлен ли `pdftotext` (`which pdftotext`, `pdftotext -v`).
8. Доступ к шлюзу Beget без ключа: `curl -s -o /dev/null -w "%{http_code}\n" https://api-llm.beget.com/v1/models`. Ожидаю `401` (сервер доступен, просит ключ). Если таймаут или ошибка соединения, покажи вывод.
9. Сводка: свободная память, версия Postgres, доступен ли pgvector, суперпользователь ли `DB_USERNAME`, сколько PDF и какой объём, есть ли `pdftotext`, доступен ли шлюз. Жди моего «да».

## 1. Резервная копия
- `pg_dump` основной базы в `~/backups/` с датой в имени, покажи размер файла.

## 2. pgvector
Если расширение `vector` недоступно (пустой результат в шаге 0.5):
- Покажи команду установки пакета под твою версию Postgres и ОС (для Debian/Ubuntu обычно `sudo apt-get install -y postgresql-<major>-pgvector`; если пакета нет в репозитории, предложи вариант с репозиторием PGDG или сборкой из исходников и остановись). Ставь после моего «да».
- Перезапуск Postgres для pgvector не нужен.

Затем включи расширение в основной базе от имени `postgres` (после «да»):
```
sudo -u postgres psql -d <база> -c "CREATE EXTENSION IF NOT EXISTS vector;"
```
Проверка: `sudo -u postgres psql -d <база> -tAc "SELECT extversion FROM pg_extension WHERE extname='vector';"` выводит версию.

Это нужно сделать заранее: миграция чата вызовет `Schema::ensureVectorExtensionExists()`, а пользователь сайта без прав суперпользователя создать расширение не сможет. Если расширение уже есть, миграция его просто пропустит.

## 3. pdftotext
Если `pdftotext` нет, после моего «да»: `sudo apt-get install -y poppler-utils`. Проверка: `pdftotext -v`. Затем на одном PDF из библиотеки проверь, что из него извлекается текст:
```
pdftotext -layout -l 2 "<путь к PDF>" - | head -40
```
Покажи первые строки. Если текста нет (пусто или мусор), значит PDF — скан, скажи об этом. Проверь так 3–4 разных файла, на русском и на английском.

## 4. Ключ ИИ-шлюза Beget
1. Ключ я создам сам в панели Beget (ИИ-шлюз → API-ключи), с месячным лимитом. В чат ключ не присылаю.
2. Я впишу в `.env` две переменные (код их пока не использует, `config:cache` не нужен):
```
BEGET_AI_URL=https://api-llm.beget.com/v1
BEGET_AI_KEY=<впишу сам>
```
3. Когда скажу, что вписал, проверь ключ, не выводя его:
```
set -a; . ./.env; set +a
curl -s "$BEGET_AI_URL/models" -H "Authorization: Bearer $BEGET_AI_KEY" | python3 -c "import json,sys; d=json.load(sys.stdin); print('\n'.join(sorted(m['id'] for m in d.get('data', []))))"
```
   Нужен список идентификаторов моделей. Пришли мне его целиком (идентификаторы — не секрет).
4. Пробный запрос векторов моделью `text-embedding-3-large` с урезанием до 1536 чисел (стоит доли копейки):
```
curl -s "$BEGET_AI_URL/embeddings" -H "Authorization: Bearer $BEGET_AI_KEY" -H "Content-Type: application/json" \
  -d '{"model":"<id text-embedding-3-large из списка>","input":"проверка","dimensions":1536}' | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d['data'][0]['embedding']) if 'data' in d else d, d.get('usage'))"
```
   Ожидаю число `1536`. Если пришло `3072`, значит шлюз не пропускает параметр `dimensions`, скажи об этом. Если пришла ошибка, покажи её целиком.
5. Пробный запрос к модели ответов (DeepSeek V4 Flash; точный id возьми из списка):
```
curl -s "$BEGET_AI_URL/chat/completions" -H "Authorization: Bearer $BEGET_AI_KEY" -H "Content-Type: application/json" \
  -d '{"model":"<id DeepSeek V4 Flash>","max_tokens":50,"messages":[{"role":"user","content":"Ответь одним словом: столица России?"}]}' | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['choices'][0]['message']['content'] if 'choices' in d else d)"
```

## 5. Копия PDF для локальной разработки (по желанию)
Спроси меня, нужна ли копия. Если да: собери все PDF из `storage/app/public/`, на которые ссылается `medical_libraries.file_path`, в архив `~/backups/medlib-pdfs-<дата>.tar.gz` с сохранением путей. Покажи размер и команду `scp`, которой я заберу его к себе.

## Что вернуть мне в конце
Коротко:
- память (всего / свободно / swap), диск, версии PHP, Postgres и ОС;
- pgvector: стоял ли, что установлено, версия расширения в базе;
- `DB_USERNAME` суперпользователь или нет;
- PDF: сколько по языкам, сколько файлов на диске, общий объём, есть ли сканы без текста, есть ли PDF у мероприятий;
- `pdftotext`: версия;
- шлюз Beget: полный список id моделей, результат пробных запросов (размер вектора и ответ модели);
- готов ли архив PDF и команда `scp`;
- что осталось сделать мне.
