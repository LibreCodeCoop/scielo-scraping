FROM php:7.4-fpm-alpine

RUN apk update && \
    apk add \
        autoconf \
        g++ \
        make

RUN pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["sh", "/usr/local/bin/entrypoint.sh"]