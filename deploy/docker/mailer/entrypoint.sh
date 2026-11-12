#!/bin/sh
set -eu
install -d -o opendkim -g opendkim -m 750 /run/opendkim
install -o opendkim -g opendkim -m 600 /run/secrets/mail_dkim /run/opendkim/mail.private
cat > /etc/opendkim.conf <<'CONF'
Syslog yes
Mode s
Canonicalization relaxed/simple
OversignHeaders From
Socket inet:8891@127.0.0.1
PidFile /run/opendkim/opendkim.pid
UserID opendkim
Domain magsender.ru
Selector mail
KeyFile /run/opendkim/mail.private
InternalHosts 127.0.0.1,172.30.93.0/28
CONF
postconf -e 'myhostname = smtp.magsender.ru' \
    'myorigin = magsender.ru' 'mydestination =' 'relay_domains =' \
    'inet_interfaces = all' 'inet_protocols = ipv4' \
    'mynetworks = 127.0.0.0/8, 172.30.93.0/28' \
    'smtpd_relay_restrictions = permit_mynetworks, reject_unauth_destination' \
    'smtpd_client_restrictions = permit_mynetworks, reject' \
    'smtpd_sender_restrictions = check_sender_access regexp:/etc/postfix/allowed-senders, reject' \
    'smtp_tls_security_level = may' 'smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt' \
    'smtpd_milters = inet:127.0.0.1:8891' 'non_smtpd_milters = inet:127.0.0.1:8891' \
    'milter_default_action = tempfail' 'milter_protocol = 6' \
    'disable_vrfy_command = yes' 'smtputf8_enable = no' \
    'maillog_file = /dev/stdout' 'message_size_limit = 10485760' \
    'maximal_queue_lifetime = 1d' 'bounce_queue_lifetime = 1d'
printf '/^noreply@magsender[.]ru$/ OK\n' > /etc/postfix/allowed-senders
# SMTP workers need access to the TCP milter and the container resolver.
postconf -F 'smtp/inet/chroot=n' 'smtp/unix/chroot=n'
postfix check
opendkim -x /etc/opendkim.conf
trap 'postfix stop; kill "$(cat /run/opendkim/opendkim.pid)" 2>/dev/null || true' TERM INT
postfix start-fg &
wait "$!"
