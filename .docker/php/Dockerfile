FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip ca-certificates curl \
    libicu-dev libzip-dev libxml2-dev zlib1g-dev \
    libcurl4-openssl-dev \
 && update-ca-certificates \
 && docker-php-ext-install intl opcache pdo pdo_mysql zip curl \
 && rm -rf /var/lib/apt/lists/*

RUN pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN { \
      echo "catch_workers_output = yes"; \
      echo "decorate_workers_output = no"; \
    } >> /usr/local/etc/php-fpm.d/zz-override.conf

RUN git config --system --add safe.directory /var/www/html
