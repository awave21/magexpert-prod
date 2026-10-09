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
