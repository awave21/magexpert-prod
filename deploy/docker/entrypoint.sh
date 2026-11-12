#!/bin/sh
set -eu
ln -sfn /run/secrets/application_env /var/www/html/.env
mkdir -p storage/app/public storage/app/private storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache --no-interaction
chown -R www-data:www-data bootstrap/cache
chmod 640 bootstrap/cache/config.php
if [ "$1" = php-fpm ]; then
    exec "$@"
fi
exec gosu www-data "$@"
