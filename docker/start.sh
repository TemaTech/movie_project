#!/bin/bash
set -e

# PHP-FPMの起動
php-fpm -D

# Nginxをフォアグラウンドで起動
nginx -g "daemon off;" 