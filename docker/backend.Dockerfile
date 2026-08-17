FROM php:8.3-fpm-alpine

RUN apk add --no-cache libzip-dev libpng-dev oniguruma-dev icu-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["sh", "-c", "php artisan migrate --force && php artisan db:seed --force && php-fpm"]
