#!/bin/bash
set -e

# CVE-2026-109618 / CVE-2026-109619 の修正版以外では公開しない。
# 8.4.5 のように番号が大きくても、各系統の修正版未満は対象になる。
if ! php -r 'exit(
    (version_compare(PHP_VERSION, "8.2.35", ">=") && version_compare(PHP_VERSION, "8.3.0", "<"))
    || (version_compare(PHP_VERSION, "8.3.36", ">=") && version_compare(PHP_VERSION, "8.4.0", "<"))
    || (version_compare(PHP_VERSION, "8.4.27", ">=") && version_compare(PHP_VERSION, "8.5.0", "<"))
    || (version_compare(PHP_VERSION, "8.5.12", ">=") && version_compare(PHP_VERSION, "8.6.0", "<"))
    ? 0 : 1);'; then
    echo "Refusing to start: PHP $(php -r 'echo PHP_VERSION;') is not a patched release for CVE-2026-109618 and CVE-2026-109619." >&2
    exit 1
fi

# Cronサービスの起動
echo "Starting Cron..."
# 環境変数をcron用に書き出し（DB接続情報やAPIキーなど）
printenv | grep -E '^(DB_|APP_|GEMINI_|TZ|HOME|PATH)' > /var/www/html/.env.cron
chmod 644 /var/www/html/.env.cron
echo "Environment variables exported for cron"
service cron start

echo "Setting up permissions..."
chmod -R 777 /var/www/html/storage
chmod -R 777 /var/www/html/storage/logs
chmod -R 777 /var/www/html/storage/framework/sessions
chmod -R 777 /var/www/html/bootstrap/cache

# セッションファイルをクリーンアップ
echo "Cleaning up session files..."
rm -rf /var/www/html/storage/framework/sessions/*

echo "Configuring PHP-FPM..."
sed -i 's/listen = \/var\/run\/php-fpm.sock/listen = 127.0.0.1:9000/g' /usr/local/etc/php-fpm.d/www.conf

# Nginxのログディレクトリの権限を確認
echo "Setting up Nginx logs..."
mkdir -p /var/log/nginx
chown -R www-data:www-data /var/log/nginx

# PHP-FPMの起動
echo "Starting PHP-FPM..."
php-fpm -D

echo "Configuring Nginx port..."
# RenderのPORT環境変数を使用
export PORT=${PORT:-8080}
sed -i "s/listen 8080/listen ${PORT}/g" /etc/nginx/sites-available/default

echo "Clearing Laravel cache..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan clear-compiled

# セッションディレクトリの権限を再確認
echo "Verifying session directory permissions..."
chown -R www-data:www-data /var/www/html/storage/framework/sessions
chmod -R 775 /var/www/html/storage/framework/sessions

echo "Verifying Nginx configuration..."
nginx -t

# データベース接続を待機
echo "Waiting for database connection..."
until php artisan db:monitor; do
    echo "Database is unavailable - sleeping"
    sleep 2
done

# マイグレーションを実行
echo "Running database migrations..."
php artisan migrate --force || true

# Nginxの起動
echo "Starting Nginx..."
nginx -g "daemon off;" 