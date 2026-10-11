# Postfix для Sender

Скрипт `setup.sh` ставит Postfix и OpenDKIM в режиме «только отправка»:
слушает только `127.0.0.1`, принимать почту из интернета не будет (MX домена остаётся у Mail.ru).

Перед запуском должно быть готово:
1. A-запись `mail.mag-expert.ru` → IP VPS.
2. Beget выставил PTR для IP на `mail.mag-expert.ru` и подтвердил открытый исходящий порт 25.
3. Домен добавлен в Sender (`php artisan sender:domain mag-expert.ru`), в DNS внесены записи DKIM и SPF.

Порядок:
```bash
# на машине с проектом
php artisan sender:dkim-export mag-expert.ru /tmp/dkim.key
scp /tmp/dkim.key deploy/postfix/setup.sh root@VPS:/root/
# на VPS
sudo bash setup.sh mag-expert.ru mail.mag-expert.ru mail /root/dkim.key
shred -u /root/dkim.key
```

SPF основного домена уже занят Mail.ru, поэтому запись нужно дополнить, а не заменить:
`v=spf1 include:_spf.mail.ru ip4:<IP VPS> ~all`

## Медленная отправка в Mail.ru, Яндекс и Gmail

`throttle.sh` добавляет отдельные транспорты для этих сервисов: одно соединение и не больше письма в секунду
(около 3600 писем в час на каждый), на остальные домены до 5 соединений. Запускается один раз после `setup.sh`,
повторный запуск безопасен, копии старых конфигов сохраняются с суффиксом `.before-throttle`.
```bash
scp deploy/postfix/throttle.sh root@VPS:/root/
sudo bash /root/throttle.sh
```
