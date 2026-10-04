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
    "ETICAD_DB_CONNECTION" => getenv("ETICAD_DB_CONNECTION") ?: "mysql",
    "ETICAD_DB_HOST" => getenv("ETICAD_DB_HOST") ?: (getenv("DB_HOST") ?: "mysql"),
    "ETICAD_DB_PORT" => getenv("ETICAD_DB_PORT") ?: "3306",
    "ETICAD_DB_DATABASE" => getenv("ETICAD_DB_DATABASE") ?: "eticad",
    "ETICAD_DB_USERNAME" => getenv("ETICAD_DB_USERNAME") ?: (getenv("DB_USERNAME") ?: "eti"),
    "ETICAD_DB_PASSWORD" => getenv("ETICAD_DB_PASSWORD") ?: (getenv("DB_PASSWORD") ?: ""),
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

php -r '
$host = getenv("DB_HOST") ?: "mysql";
$rootPass = getenv("DB_ROOT_PASSWORD") ?: "";
$user = getenv("ETICAD_DB_USERNAME") ?: (getenv("DB_USERNAME") ?: "eti");
$db = getenv("ETICAD_DB_DATABASE") ?: "eticad";
if (!preg_match("/^[A-Za-z0-9_]+$/", $db)) {
    fwrite(STDERR, "Невалидно име на базата за ETICAD\n");
    exit(1);
}
$pdo = new PDO("mysql:host=".$host.";charset=utf8mb4", "root", $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `".$db."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON `".$db."`.* TO ".$pdo->quote($user)."@".$pdo->quote("%"));
$pdo->exec("FLUSH PRIVILEGES");
'

if [ ! -f /var/www/backend/storage/app/eticad/eticad.sql ] && [ -f /var/www/eticad-seed/eticad.sql ]; then
  mkdir -p /var/www/backend/storage/app/eticad
  cp -a /var/www/eticad-seed/. /var/www/backend/storage/app/eticad/
  chown -R www-data:www-data /var/www/backend/storage/app/eticad
fi

php artisan migrate --force
php artisan catalog:ensure-offer-codes
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(Illuminate\Support\Facades\DB::table("users")->count() > 0 ? 0 : 1);
' || php artisan db:seed --force
php artisan eticad:load-mysql
exec php-fpm
