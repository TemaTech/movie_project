#!/bin/bash
set -e

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