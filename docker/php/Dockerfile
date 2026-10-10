FROM php:8.4-fpm-bookworm

ARG HOST_UID=1000
ARG HOST_GID=1000
ARG REDIS_EXTENSION_VERSION=6.3.0
ARG RDKAFKA_EXTENSION_VERSION=6.0.5

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILESIZE=1024M \
    PHP_POST_MAX_SIZE=1024M

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        jq \
        unzip \
        zip \
        default-mysql-client \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        librdkafka-dev \
        procps \
    && docker-php-ext-install -j2 \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Usa os tarballs oficiais com versoes fixas. Assim a construcao nao depende
# do indice REST do PECL, que pode falhar mesmo quando o pacote existe.
RUN curl --fail --show-error --silent --location \
        --retry 5 --retry-delay 2 --connect-timeout 20 \
        "https://pecl.php.net/get/redis-${REDIS_EXTENSION_VERSION}.tgz" \
        --output /tmp/redis.tgz \
    && curl --fail --show-error --silent --location \
        --retry 5 --retry-delay 2 --connect-timeout 20 \
        "https://pecl.php.net/get/rdkafka-${RDKAFKA_EXTENSION_VERSION}.tgz" \
        --output /tmp/rdkafka.tgz \
    && pecl install /tmp/redis.tgz \
    && pecl install /tmp/rdkafka.tgz \
    && docker-php-ext-enable redis rdkafka \
    && rm -rf /tmp/pear /tmp/redis.tgz /tmp/rdkafka.tgz

RUN curl --fail --show-error --silent --location \
        --retry 5 --retry-delay 2 --connect-timeout 20 \
        https://getcomposer.org/installer \
        --output /tmp/composer-setup.php \
    && php /tmp/composer-setup.php \
        --install-dir=/usr/local/bin \
        --filename=composer \
    && rm -f /tmp/composer-setup.php

# Os nomes app:app precisam existir porque tambem sao usados no pool PHP-FPM.
# -o permite mapear o UID/GID do host mesmo se a imagem ja tiver esses numeros.
RUN groupadd -o -g "${HOST_GID}" app \
    && useradd -o -m -u "${HOST_UID}" -g app -s /bin/bash app \
    && mkdir -p /var/www/html /var/www/storage \
    && chown -R app:app /var/www/html /var/www/storage

COPY php.ini /usr/local/etc/php/conf.d/99-crm.ini
COPY opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

USER app
