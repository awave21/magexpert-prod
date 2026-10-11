Задача: настроить на моём VPS Postfix и OpenDKIM для отправки писем моего сервиса рассылок. Только отправка, принимать почту из интернета не нужно. Ниже контекст, порядок действий и готовый скрипт `setup.sh` (сохрани его на сервере как setup.sh).

## Контекст
- Домен отправителя: `mag-expert.ru`. Почтовый хост: `mail.mag-expert.ru` (A-запись указывает на `62.217.181.42`, это IP этого VPS). PTR для этого IP на `mail.mag-expert.ru` уже заказан у Beget и должен вот-вот появиться.
- Почта основного домена `mag-expert.ru` живёт у Mail.ru для бизнеса (MX `emx.mail.ru`, SPF `redirect=_spf.mail.ru`). Её нельзя ломать: MX основного домена не менять, SPF только дополнять.
- Приложение (Laravel) стоит на этом же VPS и будет отправлять письма на `127.0.0.1:25` без авторизации.
- DNS домена хостится у Beget. DNS-записи я вношу сам в панели Beget, ты только говоришь, что внести.
- Ключ DKIM: файла с ключом у тебя нет, скрипт сам сгенерирует его на сервере (селектор `mail`) и в конце выведет значение TXT-записи для DNS. Приватный ключ не показывай в чате.
- Система на VPS: определи сам (`cat /etc/os-release`). Скрипт рассчитан на Debian/Ubuntu. Если там другая система, остановись и скажи мне.

## Порядок действий
1. **Предпроверки (ничего не меняя):**
   - ОС и версия: `cat /etc/os-release`.
   - Исходящий порт 25 наружу: `nc -vz -w 8 gmail-smtp-in.l.google.com 25`. Если соединения нет, остановись и скажи мне (Beget заявил, что порты не ограничены, но проверить нужно).
   - PTR: `dig +short -x 62.217.181.42`. Должно вернуться `mail.mag-expert.ru.`; если пусто, скажи мне, настройку можно продолжить.
   - Не слушает ли уже кто-то порт 25: `ss -tlnp | grep ':25 '`. Если там уже работает другой почтовый сервис, остановись и покажи мне, чужую настройку не перезаписывай.
2. **Покажи мне скрипт ниже** и дождись моего подтверждения, затем запусти:
   `sudo bash setup.sh mag-expert.ru mail.mag-expert.ru mail`
3. **Скрипт выведет** значение TXT для `mail._domainkey.mag-expert.ru`. Покажи его мне целиком (это публичная часть ключа, её можно показывать). Приватный ключ остаётся в `/etc/opendkim/keys/mag-expert.ru/mail.private`.
4. **Проверки:**
   - `systemctl status postfix opendkim` (оба active).
   - `postconf -n | grep -E 'myhostname|mydestination|inet_interfaces|milter'`.
   - `ss -tlnp | grep -E ':(25|8891)'` (только `127.0.0.1`).
   - `opendkim-testkey -d mag-expert.ru -s mail -vvv`: пока я не внёс DNS-запись, будет «key not found», это нормально; после внесения должно быть «key OK».
5. **Тестовое письмо** на мой адрес (спроси у меня адрес):
   `echo 'Тест' | mail -s 'Тест Postfix' -a 'From: noreply@mag-expert.ru' мой@адрес`
   Смотри журнал: `journalctl -u postfix -n 50` или `/var/log/mail.log`. Нужен статус `status=sent`. Когда я пришлю заголовки полученного письма, разбери Authentication-Results (spf, dkim, dmarc).
6. Если письмо уходит в спам или отклоняется, найди причину по журналу (PTR, SPF, DKIM, репутация IP, чёрные списки на mxtoolbox.com/blacklists) и предложи исправления.

## DNS-записи, которые я внесу сам (подскажи значения)
- SPF на `mag-expert.ru` (TXT): `v=spf1 include:_spf.mail.ru ip4:62.217.181.42 ~all`. Это дополнение существующего (Mail.ru остаётся), а не замена.
- DKIM на `mail._domainkey.mag-expert.ru` (TXT): значение из вывода скрипта (содержимое `/etc/opendkim/keys/mag-expert.ru/mail.txt`, собери в одну строку `v=DKIM1; k=rsa; p=...`).
- DMARC на `_dmarc.mag-expert.ru` уже есть, но с адресом-заглушкой `postmaster@your.tld`. Предложи реальный адрес для `rua` (я назову).

## Ограничения
- Ничего не делай с портами и службами, кроме Postfix и OpenDKIM, и не меняй файрвол без моего подтверждения.
- Не принимай входящую почту (не открывай 25 наружу), не включай аутентификацию SMTP.
- Не выводи приватный ключ и пароли в чат.
- Перед любым необратимым действием (удаление, перезапись чужих конфигов) спрашивай меня.

## Что мне вернуть
Кратко: результаты предпроверок (порт 25, PTR, ОС), значение TXT для DKIM, вывод проверок из пункта 4, статус тестового письма и любые проблемы с рекомендацией.

## Скрипт setup.sh

```bash
#!/usr/bin/env bash
# Настройка Postfix + OpenDKIM для отправки писем Sender (только отправка, без приёма почты).
# Рассчитан на Debian/Ubuntu. Запускать от root:
#   sudo bash setup.sh <домен> <почтовый-хост> <селектор> [файл-с-приватным-ключом-dkim]
# Если файл ключа не указан, ключ DKIM генерируется на сервере (его потом нужно загрузить в Sender).
# Пример:
#   sudo bash setup.sh mag-expert.ru mail.mag-expert.ru mail /root/dkim.key
set -euo pipefail

DOMAIN="${1:?Укажите домен, например mag-expert.ru}"
HOSTNAME_FQDN="${2:?Укажите почтовый хост, например mail.mag-expert.ru}"
SELECTOR="${3:?Укажите селектор DKIM, например mail}"
KEY_FILE="${4:-}"

[ "$(id -u)" -eq 0 ] || { echo "Запустите от root (sudo)"; exit 1; }
if [ -n "$KEY_FILE" ]; then
    [ -f "$KEY_FILE" ] || { echo "Файл ключа не найден: $KEY_FILE"; exit 1; }
    grep -q "PRIVATE KEY" "$KEY_FILE" || { echo "$KEY_FILE не похож на приватный ключ"; exit 1; }
fi

echo "== Пакеты"
export DEBIAN_FRONTEND=noninteractive
echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
echo "postfix postfix/mailname string ${HOSTNAME_FQDN}" | debconf-set-selections
apt-get update -y
apt-get install -y postfix opendkim opendkim-tools mailutils

echo "== OpenDKIM"
install -d -o opendkim -g opendkim -m 750 "/etc/opendkim/keys/${DOMAIN}"
GENERATED=0
if [ -n "$KEY_FILE" ]; then
    install -o opendkim -g opendkim -m 600 "$KEY_FILE" "/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.private"
elif [ ! -f "/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.private" ]; then
    opendkim-genkey -b 2048 -d "$DOMAIN" -D "/etc/opendkim/keys/${DOMAIN}" -s "$SELECTOR"
    chown opendkim:opendkim "/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.private"
    chmod 600 "/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.private"
    GENERATED=1
fi

cat > /etc/opendkim.conf <<CONF
Syslog                  yes
UMask                   007
Mode                    s
Canonicalization        relaxed/simple
SubDomains              no
OversignHeaders         From
Socket                  inet:8891@127.0.0.1
PidFile                 /run/opendkim/opendkim.pid
UserID                  opendkim
KeyTable                /etc/opendkim/key.table
SigningTable            refile:/etc/opendkim/signing.table
InternalHosts           127.0.0.1
CONF

echo "${SELECTOR}._domainkey.${DOMAIN} ${DOMAIN}:${SELECTOR}:/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.private" > /etc/opendkim/key.table
echo "*@${DOMAIN} ${SELECTOR}._domainkey.${DOMAIN}" > /etc/opendkim/signing.table
chown -R opendkim:opendkim /etc/opendkim

echo "== Postfix (только отправка)"
postconf -e "myhostname = ${HOSTNAME_FQDN}"
postconf -e "myorigin = ${DOMAIN}"
postconf -e "mydestination = localhost"
postconf -e "inet_interfaces = loopback-only"
postconf -e "inet_protocols = ipv4"
postconf -e "mynetworks = 127.0.0.0/8"
postconf -e "relayhost ="
postconf -e "smtp_tls_security_level = may"
postconf -e "smtp_tls_loglevel = 1"
postconf -e "smtpd_milters = inet:127.0.0.1:8891"
postconf -e "non_smtpd_milters = inet:127.0.0.1:8891"
postconf -e "milter_default_action = accept"
postconf -e "milter_protocol = 6"
postconf -e "disable_vrfy_command = yes"
postconf -e "smtputf8_enable = no"

postfix check
systemctl enable opendkim postfix
systemctl restart opendkim
systemctl restart postfix

echo "== Проверка"
if ss -tlnp | grep -E ':(25|8891)\b' | grep -v '127.0.0.1' | grep -q .; then
    echo "ВНИМАНИЕ: порт 25 или 8891 слушает не только localhost:"
    ss -tlnp | grep -E ':(25|8891)\b'
else
    echo "OK: Postfix и OpenDKIM слушают только 127.0.0.1"
fi

if [ "$GENERATED" -eq 1 ]; then
    echo
    echo "Ключ DKIM сгенерирован. Значение для DNS (TXT на ${SELECTOR}._domainkey.${DOMAIN}):"
    cat "/etc/opendkim/keys/${DOMAIN}/${SELECTOR}.txt"
    echo "Загрузить ключ в Sender: php artisan sender:dkim-import ${DOMAIN} <копия ${SELECTOR}.private с правами чтения>"
fi

echo
echo "Готово. Для Laravel (.env): MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=25 MAIL_USERNAME=null MAIL_PASSWORD=null MAIL_SCHEME=null"
echo "Тестовое письмо: echo 'Тест' | mail -s 'Тест Postfix' -a 'From: noreply@${DOMAIN}' you@example.com"
echo "Журнал: journalctl -u postfix -f   (или /var/log/mail.log)"
```
