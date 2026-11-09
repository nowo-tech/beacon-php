# Development / test image (PHP CLI Alpine + PCOV + Composer). PHP_VERSION: 8.2 … 8.5.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache git unzip bash \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
