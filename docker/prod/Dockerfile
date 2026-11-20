# RightAlly production image (DP1, docs/ops/01-vps-whm-deploy.md).
# One image runs the app (PHP-FPM), the queue workers and the scheduler; the "web" target is nginx with the built
# public files. The same image moves unchanged to DigitalOcean or AWS.
#
#   docker compose -f compose.prod.yaml build      (the deploy script does this)

# --- PHP with every extension and tool RightAlly needs (same as CI and docker/php) ------------------------------
# OPcache is part of PHP itself since 8.5, so it is not installed separately (docker/prod/php.ini switches it on).
FROM php:8.5-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        qpdf poppler-utils tesseract-ocr tesseract-ocr-eng \
        git unzip libicu-dev libzip-dev libpng-dev libonig-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install intl zip pdo_mysql bcmath pcntl gd \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# --- PHP packages, without development tools --------------------------------------------------------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# --- The screens: Vite needs PHP too (Wayfinder reads the routes) ------------------------------------------------
FROM base AS assets
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN composer dump-autoload --no-dev --optimize \
    && cp .env.example .env && php artisan key:generate --force && touch database/database.sqlite \
    && (npm run build || (sleep 20 && npm run build)) \
    && rm -rf node_modules .env database/database.sqlite

# --- The app: code, PHP packages and built screens --------------------------------------------------------------
FROM base AS app
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-rightally.ini
COPY docker/prod/fpm.conf /usr/local/etc/php-fpm.d/zz-rightally.conf
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize \
    && rm -rf tests e2e node_modules docker/php docker/nginx .github \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && rm -f public/storage && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache
ARG APP_RELEASE=dev
ARG APP_COMMIT=
ENV APP_RELEASE=${APP_RELEASE} APP_COMMIT=${APP_COMMIT}
LABEL rightally.release=${APP_RELEASE}
COPY --chmod=755 docker/prod/entrypoint.sh /usr/local/bin/rightally-entrypoint
USER www-data
ENTRYPOINT ["rightally-entrypoint"]
CMD ["php-fpm"]

# --- The web server: nginx with the public files, passing PHP to the app ----------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
