#!/usr/bin/env bash
# Медленная отправка в крупные почтовые сервисы: не больше одного соединения и одного письма в секунду
# на Mail.ru, Яндекс и Gmail, по 5 соединений на остальные домены. Защищает репутацию нового IP:
# даже если приложение отдаст Postfix пачку сразу, в сервисы она уйдёт постепенно.
# Повторный запуск безопасен. Запускать от root на сервере, где уже работает setup.sh:
#   sudo bash throttle.sh
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Запустите от root (sudo)"; exit 1; }

cp -n /etc/postfix/main.cf /etc/postfix/main.cf.before-throttle || true
cp -n /etc/postfix/master.cf /etc/postfix/master.cf.before-throttle || true

cat > /etc/postfix/transport <<'MAP'
mail.ru      slow_mailru:
bk.ru        slow_mailru:
list.ru      slow_mailru:
inbox.ru     slow_mailru:
internet.ru  slow_mailru:
yandex.ru    slow_yandex:
ya.ru        slow_yandex:
yandex.com   slow_yandex:
narod.ru     slow_yandex:
gmail.com    slow_gmail:
googlemail.com slow_gmail:
MAP
postmap /etc/postfix/transport

for t in slow_mailru slow_yandex slow_gmail; do
    if ! grep -q "^${t} " /etc/postfix/master.cf; then
        echo "${t}  unix  -  -  n  -  2  smtp" >> /etc/postfix/master.cf
    fi
    # при rate_delay больше нуля Postfix шлёт в домен по одному письму с этой паузой
    postconf -e "${t}_destination_concurrency_limit = 1"
    postconf -e "${t}_destination_rate_delay = 1s"
    postconf -e "${t}_destination_recipient_limit = 1"
done

postconf -e "transport_maps = hash:/etc/postfix/transport"
postconf -e "default_destination_concurrency_limit = 5"
# повторы при временных отказах (421) — двое суток, дальше письмо считается недоставленным
postconf -e "maximal_queue_lifetime = 2d"
postconf -e "bounce_queue_lifetime = 2d"

postfix check
systemctl reload postfix

echo "Готово. Проверка: postconf -n | grep -E 'transport_maps|slow_|queue_lifetime'"
echo "Откат: cp /etc/postfix/main.cf.before-throttle /etc/postfix/main.cf && cp /etc/postfix/master.cf.before-throttle /etc/postfix/master.cf && systemctl reload postfix"
