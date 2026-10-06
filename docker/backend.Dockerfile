FROM php:8.4-fpm-alpine

RUN apk add --no-cache libzip-dev libpng-dev oniguruma-dev icu-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN mkdir -p /var/www/eticad-seed \
    && cp -a storage/app/eticad/. /var/www/eticad-seed/ \
    && rm -rf storage/app/eticad \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf '\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && printf 'upload_max_filesize=32M\npost_max_size=40M\n' > /usr/local/etc/php/conf.d/uploads.ini

COPY docker/backend-entrypoint.sh /usr/local/bin/backend-entrypoint.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.sh

EXPOSE 9000

CMD ["/usr/local/bin/backend-entrypoint.sh"]
