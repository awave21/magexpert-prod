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
