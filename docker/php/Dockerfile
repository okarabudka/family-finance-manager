# syntax=docker/dockerfile:1

FROM php:8.5-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache fcgi \
    && install-php-extensions intl pdo_pgsql redis zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html

HEALTHCHECK --interval=5s --timeout=3s --start-period=5m --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

# Development image: source is bind-mounted, the container runs as the host user.
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache git unzip \
    && install-php-extensions pcov
RUN mkdir -p /var/www/html/vendor /tmp/composer \
    && chown -R "$UID:$GID" /var/www/html /tmp/composer

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
USER ${UID}:${GID}

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# Builds the production application (vendor, compiled assets, warmed cache).
FROM base AS build

ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN rm -f .env.local .env.*.local \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console assets:install public \
    && php bin/console importmap:install \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var

FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=build /var/www/html /var/www/html
USER www-data

# nginx serving the compiled public/ directory of the production build.
FROM nginx:1.29-alpine AS nginx

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public
HEALTHCHECK --interval=10s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1/nginx-health || exit 1
