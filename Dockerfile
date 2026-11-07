# Production image for Render (or any Docker host).
# Runs Apache + PHP, the queue worker and the Laravel scheduler in one container.

# ── 1. PHP dependencies ───────────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader \
        --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --ignore-platform-reqs

# ── 2. Frontend assets (Vite + Tailwind) ──────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
# Tailwind scans Laravel's pagination views (see resources/css/app.css @source).
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination \
     ./vendor/laravel/framework/src/Illuminate/Pagination
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ── 3. Runtime ────────────────────────────────────────────────────────────────
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql opcache \
    && apt-mark manual libpq5 \
    && apt-get purge -y --auto-remove libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers remoteip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
                storage/logs bootstrap/cache \
    && rm -f .env \
    && chown -R www-data:www-data storage bootstrap/cache

# Render injects PORT at runtime; 10000 is its default.
ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
