# ============================================================
# HOTEL / HONEYPOT CONNECTOR
# Laravel 12 / Render / Clever Cloud
# PHP 8.3 + Apache + Vite
# ============================================================

FROM node:20-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build


FROM php:8.3-apache-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    ca-certificates \
    libcurl4-openssl-dev \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        zip \
        gd \
        curl \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    Options -Indexes +FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN printf '%s\n' \
    'memory_limit=384M' \
    'upload_max_filesize=25M' \
    'post_max_size=25M' \
    'max_execution_time=90' \
    'expose_php=Off' \
    'opcache.enable=1' \
    'opcache.memory_consumption=128' \
    'opcache.interned_strings_buffer=16' \
    'opcache.max_accelerated_files=20000' \
    'opcache.validate_timestamps=0' \
    > /usr/local/etc/php/conf.d/hotel-production.ini

EXPOSE 10000

CMD ["sh", "-c", "\
    set -e; \
    sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf; \
    sed -i \"s/:80>/:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf; \
    php artisan optimize:clear; \
    php artisan migrate --force; \
    php artisan storage:link 2>/dev/null || true; \
    php artisan config:cache; \
    php artisan route:cache; \
    php artisan view:cache; \
    exec apache2-foreground \
"]