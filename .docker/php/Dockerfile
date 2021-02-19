FROM php:7.4-fpm

RUN apt-get update -y && \
    apt-get install -y \
        autoconf \
        g++ \
        git \
        libicu-dev \
        make \
        zip

RUN docker-php-ext-configure intl \
    && docker-php-ext-install gettext \
    && docker-php-ext-install intl

RUN pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug

# Install MySQL PDO
RUN apt-get install -y git libpq-dev libzip-dev \
    && docker-php-ext-configure mysqli -with-mysqli=mysqlnd \
    && docker-php-ext-configure pdo_mysql -with-pdo-mysql=mysqlnd \
    && docker-php-ext-install pdo pdo_mysql mysqli

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["sh", "/usr/local/bin/entrypoint.sh"]