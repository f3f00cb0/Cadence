FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        git zip unzip libzip-dev postgresql-dev icu-dev oniguruma-dev shadow \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql zip intl opcache \
    && groupmod -g ${GID} www-data \
    && usermod -u ${UID} -g ${GID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

USER www-data

WORKDIR /var/www/html
