Задача: открыть админку Sender на поддомене https://mail.mag-expert.ru. Это то же Laravel-приложение из /var/www/magexpert, нужен только второй сайт в nginx и HTTPS-сертификат. A-запись mail.mag-expert.ru уже указывает на этот сервер (62.217.181.42), на этом же имени работает Postfix (порт 25), HTTPS на 443 ему не мешает.

Работай по шагам, после каждого показывай результат. Перед изменением конфигов покажи мне, что собираешься записать. Ничего не делай с Postfix, OpenDKIM, файрволом и конфигом основного сайта.

## 1. Разведка
- Конфиг основного сайта: `ls /etc/nginx/sites-enabled/` и `cat` того файла, где `server_name mag-expert.ru`.
- Как выпущен его сертификат: `sudo certbot certificates` (если certbot есть) или путь `ssl_certificate` в конфиге.
- Версия PHP-FPM и сокет: из `fastcgi_pass` в конфиге основного сайта.

## 2. Сайт для mail.mag-expert.ru
Создай `/etc/nginx/sites-available/mail.mag-expert.ru` по образцу основного сайта:
- `server_name mail.mag-expert.ru;`
- тот же `root /var/www/magexpert/public;`, тот же `index index.php;`
- тот же блок `location ~ \.php$` с тем же `fastcgi_pass`
- `location / { try_files $uri $uri/ /index.php?$query_string; }`
- сначала только `listen 80;` (HTTPS добавит certbot)

Затем:
```
sudo ln -s /etc/nginx/sites-available/mail.mag-expert.ru /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 3. HTTPS
```
sudo certbot --nginx -d mail.mag-expert.ru
```
Если certbot нет или основной сайт получает сертификат иначе, остановись и скажи мне.

## 4. Laravel
В `/var/www/magexpert/.env` добавь строку (значения остальных переменных не показывай):
```
SENDER_UI_URL=https://mail.mag-expert.ru
```
Затем:
```
cd /var/www/magexpert && php artisan optimize
```

## 5. Проверка
```
curl -s -o /dev/null -w "%{http_code}\n" https://mail.mag-expert.ru/
curl -s -o /dev/null -w "%{http_code}\n" https://mail.mag-expert.ru/templates
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" https://mag-expert.ru/sender/domains
curl -s -o /dev/null -w "%{http_code}\n" https://mail.mag-expert.ru/api/sender/v1/admin/me
curl -s -o /dev/null -w "%{http_code}\n" https://mag-expert.ru/
```
Ожидаю: 200, 200, `301 https://mail.mag-expert.ru/domains`, 401, 200 (основной сайт не пострадал).

## Откат
Убери `SENDER_UI_URL` из `.env`, выполни `php artisan optimize`. Сайт nginx можно отключить: `sudo rm /etc/nginx/sites-enabled/mail.mag-expert.ru && sudo nginx -t && sudo systemctl reload nginx`.
