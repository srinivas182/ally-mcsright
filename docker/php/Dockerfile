# RightAlly application image (PHP-FPM). Local development and CI parity.
FROM php:8.5-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    qpdf poppler-utils tesseract-ocr tesseract-ocr-eng \
        git unzip libicu-dev libzip-dev libpng-dev libonig-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install intl zip pdo_mysql bcmath opcache pcntl gd \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
