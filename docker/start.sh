#!/bin/bash
set -e

echo "Setting up permissions..."
chmod -R 777 /var/www/html/storage

echo "Configuring PHP-FPM..."
sed -i 's/listen = \/var\/run\/php-fpm.sock/listen = 127.0.0.1:9000/g' /usr/local/etc/php-fpm.d/www.conf

echo "Starting PHP-FPM..."
php-fpm -D

echo "Verifying Nginx configuration..."
nginx -t

echo "Starting Nginx..."
# RenderのPORT環境変数を使用
export PORT=${PORT:-8080}
sed -i "s/listen 8080/listen $PORT/g" /etc/nginx/sites-available/default

echo "Running Nginx..."
nginx -g "daemon off;" 