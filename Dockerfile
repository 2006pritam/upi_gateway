FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl libzip-dev libpng-dev libonig-dev libxml2-dev libcurl4-openssl-dev libicu-dev libpq-dev && docker-php-ext-install -j$(nproc) pdo_mysql pdo_pgsql mbstring zip exif pcntl bcmath gd intl opcache && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install -y --no-install-recommends nodejs && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress && npm ci && npm run build && chmod -R 775 storage bootstrap/cache && php artisan config:clear && php artisan route:clear && php artisan view:clear
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8000}





