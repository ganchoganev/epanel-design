FROM php:8.4-fpm-alpine

RUN apk add --no-cache libzip-dev libpng-dev oniguruma-dev icu-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf '\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/zz-docker.conf

COPY docker/backend-entrypoint.sh /usr/local/bin/backend-entrypoint.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.sh

EXPOSE 9000

CMD ["/usr/local/bin/backend-entrypoint.sh"]
