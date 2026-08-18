#!/bin/sh
set -e

cd /var/www/backend

php -r '
$pairs = [
    "APP_NAME" => "ETI Panel Designer",
    "APP_ENV" => getenv("APP_ENV") ?: "production",
    "APP_KEY" => getenv("APP_KEY") ?: "",
    "APP_DEBUG" => getenv("APP_DEBUG") ?: "false",
    "APP_URL" => getenv("APP_URL") ?: "",
    "ADMIN_EMAIL" => getenv("ADMIN_EMAIL") ?: "",
    "ADMIN_PASSWORD" => getenv("ADMIN_PASSWORD") ?: "",
    "ADMIN_NAME" => getenv("ADMIN_NAME") ?: "Администратор",
    "DB_CONNECTION" => getenv("DB_CONNECTION") ?: "mysql",
    "DB_HOST" => getenv("DB_HOST") ?: "mysql",
    "DB_PORT" => getenv("DB_PORT") ?: "3306",
    "DB_DATABASE" => getenv("DB_DATABASE") ?: "eti_panel",
    "DB_USERNAME" => getenv("DB_USERNAME") ?: "eti",
    "DB_PASSWORD" => getenv("DB_PASSWORD") ?: "",
    "SESSION_DRIVER" => "database",
    "CACHE_STORE" => "database",
    "QUEUE_CONNECTION" => "database",
];
$lines = [];
foreach ($pairs as $key => $value) {
    $lines[] = $key."=".json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
file_put_contents(".env", implode("\n", $lines)."\n");
'

php artisan migrate --force
php artisan db:seed --force
exec php-fpm
