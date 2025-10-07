FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libzip-dev libxml2-dev zlib1g-dev \
 && docker-php-ext-install intl opcache pdo pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*

RUN pecl install redis \
 && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Tweaks FPM
RUN { \
      echo "catch_workers_output = yes"; \
      echo "decorate_workers_output = no"; \
    } >> /usr/local/etc/php-fpm.d/zz-override.config

RUN git config --system --add safe.directory /var/www/html
