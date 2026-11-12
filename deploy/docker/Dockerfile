FROM php:8.4-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libwebp-dev \
    unzip git gosu poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_pgsql mbstring zip intl gd bcmath exif pcntl opcache \
    && php -r 'if (!function_exists("imagewebp")) { exit(1); }' \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
ARG APP_SERVICE=combined
ENV APP_SERVICE=${APP_SERVICE}
RUN mkdir -p bootstrap/cache storage/app/public storage/app/private storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction \
    && BROADCAST_CONNECTION=log BROADCAST_DRIVER=log php artisan package:discover --no-interaction \
    && if [ "$APP_SERVICE" = site ]; then rm -rf public/sender-static app/Sender; fi \
    && if [ "$APP_SERVICE" = sender ]; then rm -rf public/build database/migrations app/Console/Commands; fi \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/application.ini
COPY deploy/docker/pool.conf /usr/local/etc/php-fpm.d/zz-application.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/application-entrypoint
RUN chmod +x /usr/local/bin/application-entrypoint
ENTRYPOINT ["application-entrypoint"]
CMD ["php-fpm", "-F"]

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=php-base /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
ARG APP_SERVICE=combined
RUN --mount=type=secret,id=frontend_env,target=/app/.env.production \
    mkdir -p bootstrap/ssr \
    && if [ "$APP_SERVICE" != sender ]; then npm run build; fi \
    && if [ "$APP_SERVICE" != site ]; then npm --prefix sender-ui ci --no-audit --no-fund && npm --prefix sender-ui run build; fi \
    && if [ "$APP_SERVICE" = site ]; then rm -rf public/sender-static; fi \
    && if [ "$APP_SERVICE" = sender ]; then rm -rf public/build; fi

FROM php-base AS app
COPY --from=frontend /app/public /var/www/html/public
COPY --from=frontend /app/bootstrap/ssr /var/www/html/bootstrap/ssr

FROM nginx:stable-alpine AS web
ARG APP_SERVICE=combined
COPY --from=frontend /app/public /var/www/html/public
RUN rm -f /var/www/html/public/hot \
    && ln -s ../storage/app/public /var/www/html/public/storage \
    && if [ "$APP_SERVICE" = site ]; then rm -rf /var/www/html/public/sender-static; fi \
    && if [ "$APP_SERVICE" = sender ]; then rm -rf /var/www/html/public/build; fi
COPY deploy/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
ENV NGINX_ENVSUBST_FILTER=PHP_UPSTREAM
