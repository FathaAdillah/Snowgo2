# syntax=docker/dockerfile:1.7
# Build context: repository root. One image serves php-fpm, Horizon, scheduler and migrate.

FROM php:8.4-fpm-alpine3.22 AS base
COPY --from=mlocati/php-extension-installer:2.9 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl opcache pcntl pdo_pgsql redis zip \
    && apk add --no-cache fcgi postgresql16-client
RUN apk add --no-cache qpdf ffmpeg
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY infrastructure/docker/api/php.ini /usr/local/etc/php/conf.d/zz-snowego.ini
COPY infrastructure/docker/api/www.conf /usr/local/etc/php-fpm.d/zz-snowego.conf
COPY --chmod=755 infrastructure/scripts/healthcheck-api.sh /usr/local/bin/healthcheck-api
WORKDIR /var/www/api

FROM base AS dev
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
RUN mkdir -p storage/logs storage/framework/views && chown www-data:www-data storage/logs storage/framework/views && chmod 2775 storage/logs storage/framework/views
# Source is bind-mounted and vendor lives in the api_vendor volume; dependencies install via `make setup`.

FROM base AS vendor
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS prod
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY --from=vendor /var/www/api/vendor ./vendor
COPY apps/api ./
RUN rm -f .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p /srv/public \
    && chown -R www-data:www-data storage bootstrap/cache /srv/public
USER www-data
